<?php

namespace App\Domain\Metrics;

use App\Domain\Groups\CandidateGroupBuilder;
use App\Domain\Rules\RuleSetRegistry;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * Создание и выполнение прогонов метрик (ТЗ §7, §8). Полный диапазон
 * отчёта считается фоново один раз; результаты переиспользуются по хешу
 * параметров. Bulk-расчёт зеркалирует MetricFormulas и сверяется
 * приёмочными тестами на контрольных рядах §14.1.
 */
class MetricRunService
{
    public function __construct(private readonly CandidateGroupBuilder $groups) {}

    /**
     * Создать (или вернуть готовый) прогон полного диапазона и выполнить его.
     *
     * @return array{id: string, preset_counts: array, groups: array}
     */
    public function buildDefaultRun(string $datasetId): array
    {
        $scanCount = (int) DB::table('dataset_scans')->where('dataset_id', $datasetId)->max('ordinal');
        $from = 1;
        $to = max(1, $scanCount);

        $runId = $this->createRun($datasetId, $from, $to, isDefault: true);
        $this->compute($runId);

        $presetCounts = $this->presetCounts($runId);
        $groupsInfo = $this->groups->build($runId, $datasetId);

        $this->finishRun($runId, $presetCounts, $groupsInfo);

        return ['id' => $runId, 'preset_counts' => $presetCounts, 'groups' => $groupsInfo];
    }

    /**
     * Запросить расчёт для произвольного диапазона/профиля (фоном).
     */
    public function requestRun(string $datasetId, int $from, int $to, ?array $profileOverrides = null): string
    {
        if (! DB::table('datasets')->where('id', $datasetId)->where('status', 'ready')->exists()) {
            throw new RuntimeException('DATASET_NOT_READY');
        }
        $max = (int) DB::table('dataset_scans')->where('dataset_id', $datasetId)->max('ordinal');
        $from = max(1, $from);
        $to = min($max, $to);
        if ($to < $from) {
            throw new RuntimeException('INVALID_RANGE');
        }

        $runId = $this->createRun($datasetId, $from, $to, isDefault: false, profileOverrides: $profileOverrides);

        if (DB::table('metric_runs')->where('id', $runId)->value('status') === 'queued') {
            \App\Jobs\ComputeMetricRunJob::dispatch($runId)->onConnection('import')->onQueue('import');
        }

        return $runId;
    }

    public function compute(string $runId): void
    {
        $run = DB::table('metric_runs')->where('id', $runId)->first();
        if ($run === null) {
            throw new RuntimeException("Прогон {$runId} не найден.");
        }

        DB::table('metric_runs')->where('id', $runId)->update(['status' => 'running', 'started_at' => now(), 'error' => null]);

        try {
            $this->computeKeywordMetrics($run->id, $run->dataset_id, (int) $run->ordinal_from, (int) $run->ordinal_to);
            $groups = $this->groups->build($run->id, $run->dataset_id);
            $presetCounts = $this->presetCounts($run->id);
            $this->finishRun($runId, $presetCounts, $groups);
        } catch (\Throwable $e) {
            DB::table('metric_runs')->where('id', $runId)->update([
                'status' => 'failed',
                'error' => $e->getMessage(),
                'updated_at' => now(),
            ]);
            throw $e;
        }
    }

    public function defaultRunId(string $datasetId): ?string
    {
        return DB::table('metric_runs')
            ->where('dataset_id', $datasetId)
            ->where('status', 'ready')
            ->where('is_default', true)
            ->orderByDesc('created_at')
            ->value('id');
    }

    private function finishRun(string $runId, array $presetCounts, array $groups): void
    {
        DB::table('metric_runs')->where('id', $runId)->update([
            'status' => 'ready',
            'completed_at' => now(),
            'results_summary' => json_encode([
                'keyword_count' => $presetCounts['total'],
                'preset_counts' => $presetCounts,
                'groups' => $groups,
            ], JSON_UNESCAPED_UNICODE),
            'updated_at' => now(),
        ]);
    }

    /**
     * Готовый прогон по параметрам или создание заготовки.
     */
    private function createRun(string $datasetId, int $from, int $to, bool $isDefault, ?array $profileOverrides = null): string
    {
        $profile = $this->profile($profileOverrides);
        $rulesVersion = RuleSetRegistry::taskSignalsVersion();
        $settingsHash = hash('sha256', json_encode([
            'dataset' => $datasetId, 'from' => $from, 'to' => $to,
            'algorithm' => MetricFormulas::VERSION, 'profile' => $profile, 'rules' => $rulesVersion,
        ], JSON_UNESCAPED_UNICODE));

        $existing = DB::table('metric_runs')
            ->where('dataset_id', $datasetId)
            ->where('settings_hash', $settingsHash)
            ->first();

        if ($existing !== null && $existing->status === 'ready') {
            return $existing->id;
        }
        if ($existing !== null) {
            // Незавершённый/упавший прогон пересчитывается на том же id.
            DB::table('keyword_metrics')->where('metric_run_id', $existing->id)->delete();
            DB::table('candidate_groups')->where('metric_run_id', $existing->id)->delete();

            return $existing->id;
        }

        $id = (string) Str::uuid();
        DB::table('metric_runs')->insert([
            'id' => $id,
            'dataset_id' => $datasetId,
            'ordinal_from' => $from,
            'ordinal_to' => $to,
            'algorithm_version' => MetricFormulas::VERSION,
            'rules_version' => $rulesVersion,
            'profile' => json_encode($profile, JSON_UNESCAPED_UNICODE),
            'settings_hash' => $settingsHash,
            'status' => 'queued',
            'is_default' => $isDefault,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return $id;
    }

    /**
     * @return array{g_scale: float, a_scale: float, v_scale: float, weights: array{g: float, a: float, c: float, v: float, t: float}, min_base_for_priority: float, low_base_threshold: int}
     */
    public function profile(?array $overrides = null): array
    {
        $base = config('niche.priority');
        $profile = [
            'g_scale' => (float) $base['g_scale'],
            'a_scale' => (float) $base['a_scale'],
            'v_scale' => (float) $base['v_scale'],
            'weights' => $base['weights'],
            'min_base_for_priority' => (float) $base['min_base_for_priority'],
            'low_base_threshold' => (int) config('niche.low_base_threshold'),
        ];
        foreach ((array) $overrides as $k => $v) {
            if (array_key_exists($k, $profile)) {
                $profile[$k] = $v;
            }
        }

        return $profile;
    }

    /**
     * Bulk-расчёт keyword_metrics: зеркалирует MetricFormulas (§7.1–7.4).
     */
    private function computeKeywordMetrics(string $runId, string $datasetId, int $from, int $to): void
    {
        $n = $to - $from + 1;
        $p = $this->profile();
        $wG = (float) $p['weights']['g'];
        $wA = (float) $p['weights']['a'];
        $wC = (float) $p['weights']['c'];
        $wV = (float) $p['weights']['v'];
        $wT = (float) $p['weights']['t'];
        $gScale = (float) $p['g_scale'];
        $aScale = (float) $p['a_scale'];
        $vScale = (float) $p['v_scale'];
        $minBase = (float) $p['min_base_for_priority'];
        $lowBase = (int) $p['low_base_threshold'];

        DB::table('keyword_metrics')->where('metric_run_id', $runId)->delete();

        DB::statement("
            WITH obs AS (
                SELECT o.keyword_id, s.ordinal, o.exact, o.broad
                FROM observations o
                JOIN dataset_scans s ON s.id = o.scan_id
                WHERE s.dataset_id = ? AND s.ordinal BETWEEN ? AND ?
            ), w AS (
                SELECT keyword_id, ordinal, exact, broad,
                       lag(exact) OVER (PARTITION BY keyword_id ORDER BY ordinal) AS prev_exact
                FROM obs
            ), agg AS (
                SELECT keyword_id,
                       count(*) AS n_obs,
                       count(exact) AS n_exact,
                       min(CASE WHEN ordinal = {$from} THEN exact END) AS f_first,
                       min(CASE WHEN ordinal = {$from} + 1 THEN exact END) AS f_second,
                       min(CASE WHEN ordinal = {$to} - 1 THEN exact END) AS f_penult,
                       min(CASE WHEN ordinal = {$to} THEN exact END) AS f_last,
                       min(CASE WHEN ordinal = {$to} THEN broad END) AS b_last,
                       max(exact) AS f_max,
                       count(*) FILTER (WHERE prev_exact IS NOT NULL AND exact IS NOT NULL AND exact > prev_exact) AS pos_trans
                FROM w GROUP BY keyword_id
            ), calc AS (
                SELECT a.*,
                       (a.n_obs = {$n} AND a.n_exact = {$n}) AS complete,
                       CASE WHEN a.f_first IS NOT NULL AND a.f_last IS NOT NULL THEN a.f_last - a.f_first END AS exact_delta,
                       CASE WHEN a.f_first > 0 AND a.f_last IS NOT NULL THEN (a.f_last - a.f_first)::float8 / a.f_first END AS growth_pct,
                       CASE WHEN (a.n_obs = {$n} AND a.n_exact = {$n} AND {$n} >= 4) THEN (a.f_first + a.f_second) / 2.0 END AS base_avg,
                       CASE WHEN (a.n_obs = {$n} AND a.n_exact = {$n} AND {$n} >= 4) THEN (a.f_penult + a.f_last) / 2.0 END AS late_avg
                FROM agg a
            ), fin AS (
                SELECT c.*,
                       CASE WHEN c.base_avg IS NOT NULL THEN c.late_avg - c.base_avg END AS a_delta,
                       CASE WHEN c.base_avg > 0 AND c.late_avg IS NOT NULL THEN (c.late_avg - c.base_avg) / c.base_avg END AS g_growth,
                       CASE WHEN c.complete AND {$n} >= 4 THEN c.pos_trans::float8 / NULLIF({$n} - 1, 0) END AS cons,
                       CASE WHEN c.complete AND {$n} >= 4 AND c.f_max > 0 THEN c.f_last::float8 / c.f_max END AS peak
                FROM calc c
            )
            INSERT INTO keyword_metrics (
                metric_run_id, keyword_id, n_observations, history_complete,
                exact_current, broad_current, exact_first, exact_delta, growth_pct,
                base_avg, late_avg, smoothed_delta, smoothed_growth, consistency, peak_retention,
                task_score, task_categories, task_rule_ids, priority,
                is_new, low_base, zero_baseline, null_reasons, rank_current, rank_previous
            )
            SELECT
                ?, dk.keyword_id,
                coalesce(f.n_obs, 0), coalesce(f.complete, false),
                f.f_last, f.b_last, f.f_first, f.exact_delta, f.growth_pct,
                f.base_avg, f.late_avg, f.a_delta, f.g_growth, f.cons, f.peak,
                k.task_score, k.task_categories, k.task_rule_ids,
                CASE WHEN f.base_avg IS NOT NULL AND f.base_avg >= {$minBase}
                          AND f.g_growth IS NOT NULL AND f.a_delta IS NOT NULL
                          AND f.cons IS NOT NULL AND f.f_last IS NOT NULL
                THEN round(100 * ({$wG} * LEAST(1, GREATEST(0, f.g_growth / {$gScale}))
                    + {$wA} * LEAST(1, GREATEST(0, ln(1 + GREATEST(f.a_delta, 0)) / ln(1 + {$aScale})))
                    + {$wC} * f.cons
                    + {$wV} * LEAST(1, ln(1 + f.f_last) / ln(1 + {$vScale}))
                    + {$wT} * LEAST(1, k.task_score)))
                END,
                (dk.rank_previous = 0),
                (f.f_first IS NOT NULL AND f.f_first < {$lowBase}),
                (f.f_first = 0),
                jsonb_strip_nulls(jsonb_build_object(
                    'growth_pct', CASE WHEN f.f_first IS NOT NULL AND f.f_last IS NOT NULL AND f.f_first = 0 THEN 'zero_baseline'
                                       WHEN (f.f_first IS NULL OR f.f_last IS NULL) THEN 'missing_boundary' END,
                    'smoothed', CASE WHEN {$n} < 4 THEN 'insufficient_history'
                                     WHEN NOT coalesce(f.complete, false) THEN 'incomplete_series' END,
                    'priority', CASE WHEN f.base_avg IS NOT NULL AND f.base_avg < {$minBase} THEN 'low_base'
                                     WHEN f.base_avg IS NULL THEN 'insufficient_history' END
                )),
                dk.rank_current, dk.rank_previous
            FROM dataset_keywords dk
            LEFT JOIN fin f ON f.keyword_id = dk.keyword_id
            JOIN keywords k ON k.id = dk.keyword_id
            WHERE dk.dataset_id = ?
        ", [$datasetId, $from, $to, $runId, $datasetId]);

        DB::statement('ANALYZE keyword_metrics');
        DB::statement('ANALYZE observations');
    }

    /**
     * @return array<string, int>
     */
    public function presetCounts(string $runId): array
    {
        $counts = ['total' => (int) DB::table('keyword_metrics')->where('metric_run_id', $runId)->count()];
        foreach (array_keys(config('niche.presets')) as $key) {
            $conds = \App\Domain\Search\Presets::conditions($key, 'km');
            $counts[$key] = (int) DB::selectOne(
                "SELECT count(*) AS n FROM keyword_metrics km WHERE km.metric_run_id = ? AND {$conds}",
                [$runId]
            )->n;
        }

        return $counts;
    }
}
