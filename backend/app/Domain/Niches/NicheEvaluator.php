<?php

namespace App\Domain\Niches;

use App\Domain\Metrics\MetricRunService;
use App\Domain\Search\FilterPayload;
use App\Domain\Search\KeywordSearchService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * Оценка ниши: состав по правилу `(совпадение с фильтрами ИЛИ ручное
 * включение) И НЕ ручное исключение` (ТЗ §5.5), неизменяемая версия
 * состава и агрегаты по §7.5. Пересчёт всегда создаёт новую версию;
 * прежние результаты не меняются.
 */
class NicheEvaluator
{
    public function __construct(
        private readonly KeywordSearchService $search,
        private readonly MetricRunService $runs,
    ) {}

    /**
     * @return array{niche_version_id: string, version: int, member_count: int, complete_member_count: int, coverage_pct: float, aggregates: array}
     */
    public function evaluate(string $nicheId, string $datasetId, ?string $metricRunId = null): array
    {
        $niche = DB::table('niches')->where('id', $nicheId)->first();
        if ($niche === null) {
            throw new RuntimeException('NICHE_NOT_FOUND');
        }
        $dataset = DB::table('datasets')->where('id', $datasetId)->first();
        if ($dataset === null || $dataset->status !== 'ready') {
            throw new RuntimeException('DATASET_NOT_READY');
        }

        $runId = $metricRunId ?? $this->runs->defaultRunId($datasetId);
        if ($runId === null) {
            throw new RuntimeException('INSUFFICIENT_HISTORY: нет готового расчёта метрик');
        }
        $run = DB::table('metric_runs')->where('id', $runId)->first();

        // --- Состав ---
        $manualInclude = array_map('intval', json_decode((string) ($niche->manual_include ?? '[]'), true) ?: []);
        $manualExclude = array_map('intval', json_decode((string) ($niche->manual_exclude ?? '[]'), true) ?: []);
        $excludeSet = array_fill_keys($manualExclude, true);

        $memberIds = [];
        if ($niche->type === 'fixed') {
            // Фиксированная ниша: только ручные списки (ТЗ §5.5).
            $memberIds = $manualInclude;
        } else {
            $rule = json_decode((string) ($niche->rule ?? '{}'), true) ?: [];
            if (! empty($rule)) {
                $payload = new FilterPayload($rule);
                $this->search->streamMatches($datasetId, $runId, $payload, function ($rows) use (&$memberIds) {
                    foreach ($rows as $r) {
                        $memberIds[] = $r['keyword_id'];
                    }
                });
            }
            foreach ($manualInclude as $id) {
                $memberIds[] = $id; // ручные включения расширяют правило
            }
        }
        $memberIds = array_values(array_unique(array_filter($memberIds, fn ($id) => ! isset($excludeSet[$id]))));

        if ($memberIds === []) {
            throw new RuntimeException('EMPTY_NICHE: состав ниши пуст');
        }

        // --- Версия состава ---
        $versionNo = (int) DB::table('niche_versions')->where('niche_id', $nicheId)->max('version') + 1;
        $versionId = (string) Str::uuid();

        $presentIds = DB::table('dataset_keywords')
            ->where('dataset_id', $datasetId)
            ->whereIn('keyword_id', $memberIds)
            ->pluck('keyword_id')
            ->map(fn ($v) => (int) $v)
            ->all();
        $presentSet = array_fill_keys($presentIds, true);

        DB::table('niche_members')->insert(array_map(fn ($id) => [
            'niche_version_id' => $versionId,
            'keyword_id' => $id,
            'has_observation' => isset($presentSet[$id]),
        ], $memberIds));

        // --- Агрегаты (§7.5) ---
        $aggregates = $this->aggregates($datasetId, $run, $memberIds);

        $coverage = count($aggregates['complete_ids']) / max(1, count($memberIds));

        DB::table('niche_versions')->insert([
            'id' => $versionId,
            'niche_id' => $nicheId,
            'dataset_id' => $datasetId,
            'metric_run_id' => $runId,
            'version' => $versionNo,
            'member_count' => count($memberIds),
            'complete_member_count' => count($aggregates['complete_ids']),
            'coverage_pct' => round($coverage * 100, 2),
            'aggregates' => json_encode($aggregates['summary'], JSON_UNESCAPED_UNICODE),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return [
            'niche_version_id' => $versionId,
            'version' => $versionNo,
            'member_count' => count($memberIds),
            'complete_member_count' => count($aggregates['complete_ids']),
            'coverage_pct' => round($coverage * 100, 2),
            'aggregates' => $aggregates['summary'],
        ];
    }

    /**
     * @param  array<int, int>  $memberIds
     */
    private function aggregates(string $datasetId, object $run, array $memberIds): array
    {
        $from = (int) $run->ordinal_from;
        $to = (int) $run->ordinal_to;
        $nRange = $to - $from + 1;

        // Полные ряды участников.
        $series = [];
        foreach (array_chunk($memberIds, 5000) as $chunk) {
            $rows = DB::select("
                SELECT o.keyword_id, s.ordinal, o.exact
                FROM observations o
                JOIN dataset_scans s ON s.id = o.scan_id
                WHERE s.dataset_id = ? AND s.ordinal BETWEEN ? AND ?
                  AND o.keyword_id = ANY (?::bigint[])
                ORDER BY o.keyword_id, s.ordinal
            ", [$datasetId, $from, $to, '{'.implode(',', $chunk).'}']);
            foreach ($rows as $r) {
                $series[(int) $r->keyword_id][(int) $r->ordinal] = $r->exact !== null ? (int) $r->exact : null;
            }
        }

        $completeIds = [];
        $sums = array_fill($from, $nRange, 0);
        $firstSum = $lastSum = null;
        foreach ($series as $id => $byOrdinal) {
            if (count($byOrdinal) !== $nRange || in_array(null, $byOrdinal, true)) {
                continue; // неполная история — из динамики исключается (§7.5)
            }
            $completeIds[$id] = true;
            foreach ($byOrdinal as $ord => $v) {
                $sums[$ord] += $v;
            }
        }

        // Метрики участников из прогона.
        $metrics = [];
        foreach (array_chunk($memberIds, 5000) as $chunk) {
            $rows = DB::table('keyword_metrics as km')
                ->join('keywords as k', 'k.id', '=', 'km.keyword_id')
                ->where('km.metric_run_id', $run->id)
                ->whereIn('km.keyword_id', $chunk)
                ->get(['km.keyword_id', 'k.phrase_original', 'km.exact_current', 'km.smoothed_delta', 'km.smoothed_growth', 'km.base_avg']);
            foreach ($rows as $r) {
                $metrics[(int) $r->keyword_id] = $r;
            }
        }

        // Доля растущих (A > 0) среди K_complete.
        $growing = 0;
        $positiveBase = [];
        foreach ($completeIds as $id => $_) {
            if (isset($metrics[$id]) && (float) $metrics[$id]->smoothed_delta > 0) {
                $growing++;
            }
            if (isset($metrics[$id]) && $metrics[$id]->base_avg !== null && (float) $metrics[$id]->base_avg > 0) {
                $gs = $metrics[$id]->smoothed_growth !== null ? (float) $metrics[$id]->smoothed_growth : null;
                if ($gs !== null) {
                    $positiveBase[] = $gs;
                }
            }
        }
        sort($positiveBase);
        $medianG = $positiveBase === [] ? null
            : ($positiveBase[intdiv(count($positiveBase), 2)]);

        // Лидер и доля в текущей сумме.
        $currentKey = $to;
        $leader = null;
        $currentTotal = $sums[$currentKey] ?? 0;
        foreach ($completeIds as $id => $_) {
            $cur = $series[$id][$currentKey] ?? null;
            if ($cur !== null && ($leader === null || $cur > $leader['exact_current'])) {
                $leader = ['keyword_id' => $id, 'exact_current' => $cur,
                    'phrase' => $metrics[$id]->phrase_original ?? null];
            }
        }

        // Лидеры вклада в абсолютное изменение (§7.5).
        $contributors = [];
        foreach ($completeIds as $id => $_) {
            if (isset($metrics[$id]->smoothed_delta)) {
                $contributors[] = [
                    'keyword_id' => $id,
                    'phrase' => $metrics[$id]->phrase_original ?? null,
                    'smoothed_delta' => (float) $metrics[$id]->smoothed_delta,
                    'exact_current' => $metrics[$id]->exact_current !== null ? (int) $metrics[$id]->exact_current : null,
                ];
            }
        }
        usort($contributors, fn ($a, $b) => $b['smoothed_delta'] <=> $a['smoothed_delta']);
        $topContributors = array_slice($contributors, 0, 10);

        $dynamics = [];
        foreach ($sums as $ord => $sum) {
            $dynamics[] = ['ordinal' => $ord, 'exact_sum' => $sum];
        }
        $baseSum = $sums[$from] ?? null;
        $growthAbs = ($baseSum !== null) ? $currentTotal - $baseSum : null;
        $growthPct = ($baseSum !== null && $baseSum > 0) ? ($currentTotal - $baseSum) / $baseSum : null;

        $summary = [
            // Подпись обязательна: сумма точных выбранных формулировок, не рынок (§4.1).
            'exact_sum_label' => 'суммарная точная частотность выбранных формулировок',
            'dynamics' => $dynamics,
            'growth_abs' => $growthAbs,
            'growth_pct' => $growthPct,
            'growing_share' => count($completeIds) > 0 ? $growing / count($completeIds) : null,
            'leader' => $leader,
            'leader_share' => ($leader !== null && $currentTotal > 0) ? $leader['exact_current'] / $currentTotal : null,
            'median_smoothed_growth' => ['value' => $medianG, 'positive_base_members' => count($positiveBase)],
            'contribution_leaders' => $topContributors,
            'excluded_incomplete' => count($memberIds) - count($completeIds),
            'ordinal_from' => $from,
            'ordinal_to' => $to,
            'algorithm_version' => $run->algorithm_version,
        ];

        return ['complete_ids' => $completeIds, 'summary' => $summary];
    }
}
