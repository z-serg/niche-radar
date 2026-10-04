<?php

namespace App\Domain\Analytics;

use App\Domain\Metrics\MetricRunService;
use App\Domain\Search\FilterPayload;
use App\Domain\Search\KeywordSearchService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Агрегаты выборки (ТЗ §9 POST /analytics/summary). Тяжёлые выборки
 * считаются фоном через общий механизм jobs.
 */
class SummaryService
{
    public const SYNC_LIMIT = 200000;

    public function __construct(
        private readonly KeywordSearchService $search,
        private readonly MetricRunService $runs,
    ) {}

    /**
     * @return array{result?: array, job_id?: string}
     */
    public function summary(string $datasetId, ?string $metricRunId, array $rawFilters): array
    {
        $payload = new FilterPayload($rawFilters);
        $count = null;

        // Быстрая проверка объёма: считаем count; если велик — фоновый job.
        $count = $this->search->count($datasetId, $metricRunId, $payload);
        if ($count > self::SYNC_LIMIT) {
            $jobId = (string) Str::uuid();
            DB::table('analytics_jobs')->insert([
                'id' => $jobId,
                'kind' => 'summary',
                'params' => json_encode([
                    'dataset_id' => $datasetId,
                    'metric_run_id' => $metricRunId,
                    'filters' => json_decode($payload->canonical(), true),
                ], JSON_UNESCAPED_UNICODE),
                'status' => 'queued',
                'created_at' => now(),
                'updated_at' => now(),
            ]);
            \App\Jobs\AnalyticsSummaryJob::dispatch($jobId)->onConnection('default')->onQueue('default');

            return ['job_id' => $jobId];
        }

        return ['result' => $this->aggregate($datasetId, $metricRunId, $payload, $count)];
    }

    public function processJob(string $analyticsJobId): void
    {
        $job = DB::table('analytics_jobs')->where('id', $analyticsJobId)->first();
        if ($job === null) {
            return;
        }
        DB::table('analytics_jobs')->where('id', $analyticsJobId)->update(['status' => 'running', 'started_at' => now(), 'updated_at' => now()]);
        try {
            $params = json_decode((string) $job->params, true);
            $payload = new FilterPayload($params['filters'] ?? []);
            $result = $this->aggregate($params['dataset_id'], $params['metric_run_id'] ?? null, $payload);
            DB::table('analytics_jobs')->where('id', $analyticsJobId)->update([
                'status' => 'done', 'result' => json_encode($result, JSON_UNESCAPED_UNICODE), 'finished_at' => now(), 'updated_at' => now(),
            ]);
        } catch (\Throwable $e) {
            DB::table('analytics_jobs')->where('id', $analyticsJobId)->update([
                'status' => 'failed', 'error' => $e->getMessage(), 'finished_at' => now(), 'updated_at' => now(),
            ]);
        }
    }

    private function aggregate(string $datasetId, ?string $metricRunId, FilterPayload $payload, ?int $knownCount = null): array
    {
        $count = $knownCount ?? $this->search->count($datasetId, $metricRunId, $payload);
        if ($count === 0) {
            return [
                'count' => 0,
                'exact_sum_label' => 'суммарная точная частотность выбранных формулировок',
                'exact_sum' => 0,
                'dataset_id' => $datasetId,
                'metric_run_id' => $metricRunId,
            ];
        }

        $base = json_decode($payload->canonical(), true);
        $base['limit'] = 50000;
        $base['cursor'] = null;

        $total = 0;
        $exactSum = 0;
        $complete = 0;
        $growthSum = 0;
        $growthCount = 0;
        $deltas = [];
        $first = $last = null;

        do {
            $eff = new FilterPayload($base);
            $res = $this->search->search($datasetId, $metricRunId, $eff);
            foreach ($res['data'] as $row) {
                $total++;
                $m = $row['metrics'];
                if ($row['exact_current'] !== null) {
                    $exactSum += $row['exact_current'];
                }
                if ($m['smoothed_growth'] !== null) {
                    $growthSum += $m['smoothed_growth'];
                    $growthCount++;
                }
                if ($m['smoothed_delta'] !== null) {
                    $deltas[] = $m['smoothed_delta'];
                }
                if (! in_array('incomplete_history', $row['flags'], true)) {
                    $complete++;
                }
            }
            $base['cursor'] = $res['next_cursor'];
        } while ($base['cursor'] !== null && $total < 300000);

        sort($deltas);
        $median = $deltas === [] ? null : $deltas[intdiv(count($deltas), 2)];
        $p90 = $deltas === [] ? null : $deltas[(int) floor(count($deltas) * 0.9)];

        return [
            'count' => $count,
            'processed' => $total,
            'exact_sum_label' => 'суммарная точная частотность выбранных формулировок',
            'exact_sum' => $exactSum,
            'history_complete_share' => $total > 0 ? $complete / $total : null,
            'avg_smoothed_growth' => $growthCount > 0 ? $growthSum / $growthCount : null,
            'median_smoothed_delta' => $median,
            'p90_smoothed_delta' => $p90,
            'dataset_id' => $datasetId,
            'metric_run_id' => $metricRunId,
            'applied' => $res['applied'] ?? null,
        ];
    }
}
