<?php

namespace App\Domain\Analytics;

use App\Domain\Metrics\MetricRunService;
use App\Domain\Search\Presets;
use Illuminate\Support\Facades\DB;

/**
 * Обзор активного отчёта (ТЗ §5.7): число фраз, история, кандидаты по
 * пресету, лидеры роста и падения, новые участники, предупреждения.
 * Каждый показатель открывает исходную выборку — без декоративных цифр.
 */
class DashboardService
{
    public function __construct(private readonly MetricRunService $runs) {}

    public function overview(?string $datasetId): ?array
    {
        if ($datasetId === null) {
            return null;
        }
        $dataset = DB::table('datasets')->where('id', $datasetId)->first();
        if ($dataset === null) {
            return null;
        }
        $runId = $this->runs->defaultRunId($datasetId);
        $run = $runId ? DB::table('metric_runs')->where('id', $runId)->first() : null;

        $scans = DB::table('dataset_scans')
            ->where('dataset_id', $datasetId)
            ->orderBy('ordinal')
            ->get(['id', 'ordinal', 'source_suffix', 'scan_date']);

        $out = [
            'dataset' => [
                'id' => $dataset->id,
                'title' => $dataset->title,
                'status' => $dataset->status,
                'coverage_type' => $dataset->coverage_type,
                'row_count' => $dataset->row_count !== null ? (int) $dataset->row_count : null,
                'scan_count' => $dataset->scan_count !== null ? (int) $dataset->scan_count : null,
                'has_history' => (int) $dataset->scan_count >= 2,
                'quality_flags' => json_decode((string) $dataset->quality_flags, true) ?: [],
                'region' => $dataset->region,
                'period_description' => $dataset->period_description,
            ],
            'scans' => $scans,
            'metric_run' => $run ? [
                'id' => $run->id,
                'algorithm_version' => $run->algorithm_version,
                'rules_version' => $run->rules_version,
                'range' => [(int) $run->ordinal_from, (int) $run->ordinal_to],
                'summary' => json_decode((string) $run->results_summary, true),
            ] : null,
        ];

        if ($runId !== null) {
            $preset = Presets::conditions('sustained_growth', 'km');

            $out['preset_counts'] = $this->runs->presetCounts($runId);

            // Лидеры роста и падения (по сглаженному приросту).
            $base = 'FROM keyword_metrics km JOIN keywords k ON k.id = km.keyword_id WHERE km.metric_run_id = ?';
            $bindings = [$runId];
            $out['growth_leaders'] = DB::select(
                "SELECT km.keyword_id, k.phrase_original AS phrase, km.smoothed_delta, km.smoothed_growth, km.exact_current, km.priority {$base} AND km.smoothed_delta IS NOT NULL ORDER BY km.smoothed_delta DESC NULLS LAST, km.keyword_id LIMIT 5",
                $bindings
            );
            $out['fall_leaders'] = DB::select(
                "SELECT km.keyword_id, k.phrase_original AS phrase, km.smoothed_delta, km.smoothed_growth, km.exact_current, km.priority {$base} AND km.smoothed_delta IS NOT NULL ORDER BY km.smoothed_delta ASC NULLS LAST, km.keyword_id LIMIT 5",
                $bindings
            );

            // Новые участники рейтинга (rank_previous = 0, §4.1: это не
            // «впервые возникший спрос»).
            $out['new_entrants_count'] = (int) DB::selectOne(
                "SELECT count(*) AS n {$base} AND km.is_new", $bindings
            )->n;
            $out['new_entrants_sample'] = DB::select(
                "SELECT km.keyword_id, k.phrase_original AS phrase, km.exact_current, km.smoothed_delta {$base} AND km.is_new ORDER BY km.exact_current DESC, km.keyword_id LIMIT 5",
                $bindings
            );
        }

        $out['niches'] = DB::table('niches')
            ->leftJoin('niche_versions', 'niche_versions.niche_id', '=', 'niches.id')
            ->select('niches.id', 'niches.name', 'niches.type')
            ->selectRaw('max(niche_versions.version) as last_version')
            ->groupBy('niches.id', 'niches.name', 'niches.type')
            ->limit(10)
            ->get();

        $out['hypotheses_by_status'] = DB::table('hypotheses')
            ->select('status', DB::raw('count(*) as n'))
            ->groupBy('status')
            ->get();

        return $out;
    }
}
