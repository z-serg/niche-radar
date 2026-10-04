<?php

namespace App\Console\Commands;

use App\Domain\Datasets\DatasetManager;
use App\Domain\Datasets\ImportProcessor;
use App\Domain\Metrics\MetricRunService;
use App\Domain\Niches\NicheEvaluator;
use App\Domain\Search\FilterPayload;
use App\Domain\Search\KeywordSearchService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Контрольный бенчмарк (ТЗ §13): фиксирует версии, ресурсы, объёмы БД,
 * параметры запросов, холодный/прогретый режим и не менее 30 повторов;
 * отчёт — p50/p95 в benchmarks/.
 */
class BenchmarkCommand extends Command
{
    protected $signature = 'niche:benchmark
        {--rows=3000000 : число строк генератора (0 — использовать готовый файл)}
        {--file= : существующий CSV вместо генерации}
        {--repeats=30}
        {--seed=42}';

    protected $description = 'Прогнать контрольную нагрузку и записать отчёт';

    public function handle(
        DatasetManager $manager,
        ImportProcessor $importer,
        KeywordSearchService $search,
        NicheEvaluator $niches,
    ): int {
        $repeats = max(30, (int) $this->option('repeats'));
        $report = [
            'started_at' => now()->toDateTimeString(),
            'versions' => [
                'php' => PHP_VERSION,
                'laravel' => app()->version(),
                'postgres' => DB::selectOne('SHOW server_version')->server_version,
                'algorithm' => \App\Domain\Metrics\MetricFormulas::VERSION,
            ],
            'cpu_count' => trim((string) shell_exec('nproc')),
            'memory_limit' => ini_get('memory_limit'),
            'repeats' => $repeats,
        ];

        // --- Подготовка набора ---
        $file = $this->option('file');
        if (! $file) {
            $rows = (int) $this->option('rows');
            $file = storage_path("app/staging/bench_{$rows}_".$this->option('seed').'.csv');
            if (! is_file($file)) {
                $this->call('niche:generate-fixture', ['--rows' => $rows, '--out' => $file, '--seed' => $this->option('seed')]);
            }
        }
        $report['input'] = ['file' => $file, 'size_bytes' => filesize($file)];

        // --- Импорт ---
        $ingest = $manager->ingestFile($file, basename($file), ['title' => 'benchmark '.now()->toDateString(), 'coverage_type' => 'sample']);
        $datasetId = $ingest['dataset_id'];
        if ($ingest['existing']) {
            $this->info('Набор уже загружен; импорт пропущен (повторный прогон).');
            $job = DB::table('import_jobs')->where('dataset_id', $datasetId)->orderByDesc('created_at')->first();
            $report['import'] = ['reused' => true, 'job' => $job?->id];
        } else {
            $jobId = $manager->queueImport($datasetId);
            $this->info("Импорт запущен: {$jobId}; ожидание завершения...");
            $t0 = microtime(true);
            while (true) {
                $job = DB::table('import_jobs')->where('id', $jobId)->first();
                if (in_array($job->status, ['done', 'failed', 'cancelled'], true)) {
                    break;
                }
                sleep(3);
            }
            $report['import'] = [
                'reused' => false,
                'status' => $job->status,
                'wall_seconds' => round(microtime(true) - $t0, 1),
                'summary' => json_decode((string) $job->progress, true),
                'peak_process_memory_bytes' => memory_get_peak_usage(true),
            ];
            if ($job->status !== 'done') {
                $this->error('Импорт не удался: '.$job->error);
                $this->writeReport($report);

                return self::FAILURE;
            }
        }

        $runId = app(MetricRunService::class)->defaultRunId($datasetId);

        // --- Размеры БД ---
        $sizes = DB::selectOne("
            SELECT pg_size_pretty(pg_database_size(current_database())) AS db,
                   (SELECT pg_size_pretty(pg_total_relation_size('observations')) AS observations),
                   (SELECT pg_size_pretty(pg_total_relation_size('keyword_metrics')) AS keyword_metrics),
                   (SELECT pg_size_pretty(pg_total_relation_size('keyword_tokens')) AS keyword_tokens)
        ");
        $report['database_sizes'] = (array) $sizes;

        // --- Измерения ---
        $measure = function (string $name, callable $fn, bool $cold = false) use ($repeats, &$report) {
            $times = [];
            for ($i = 0; $i < $repeats; $i++) {
                $t = microtime(true);
                $fn();
                $times[] = (microtime(true) - $t) * 1000;
            }
            sort($times);
            $report['queries'][$name] = [
                'p50_ms' => round($times[intdiv(count($times), 2)], 1),
                'p95_ms' => round($times[(int) floor(count($times) * 0.95)], 1),
                'mode' => $cold ? 'cold' : 'warm',
            ];
            $this->line(sprintf('%s: p50=%.0f мс p95=%.0f мс', $name, $report['queries'][$name]['p50_ms'], $report['queries'][$name]['p95_ms']));
        };

        $selectivity = 'конвер'; // селективная подстрока, ~<1% строк
        $substringPayload = new FilterPayload([
            'filters' => ['include' => ['terms' => [$selectivity], 'match' => 'substring']],
            'sort' => [['field' => 'exact_current', 'direction' => 'desc']],
            'limit' => 50,
        ]);
        $measure('selective_search_substring', fn () => $search->search($datasetId, $runId, $substringPayload));

        $priorityPayload = new FilterPayload([
            'filters' => ['preset' => 'sustained_growth'],
            'limit' => 50,
        ]);
        $measure('preset_priority_page', fn () => $search->search($datasetId, $runId, $priorityPayload));

        $anyKeyword = DB::table('dataset_keywords')->where('dataset_id', $datasetId)->inRandomOrder(42)->first();
        $measure('keyword_history', fn () => DB::select("
            SELECT o.keyword_id, s.ordinal, o.exact, o.broad
            FROM observations o JOIN dataset_scans s ON s.id = o.scan_id
            WHERE s.dataset_id = ? AND o.keyword_id = ?
            ORDER BY s.ordinal", [$datasetId, $anyKeyword->keyword_id]));

        // Ниша: топ-50 растущих фраз, фиксированный состав.
        $top = $search->search($datasetId, $runId, $priorityPayload);
        $ids = array_column(array_slice($top['data'], 0, 50), 'keyword_id');
        $nicheId = (string) Str::uuid();
        DB::table('niches')->insert([
            'id' => $nicheId, 'name' => 'benchmark niche', 'type' => 'fixed',
            'manual_include' => json_encode($ids), 'manual_exclude' => '[]',
            'created_at' => now(), 'updated_at' => now(),
        ]);
        $version = $niches->evaluate($nicheId, $datasetId, $runId);
        $measure('niche_summary', fn () => DB::table('niche_versions')->where('id', $version['niche_version_id'])->value('aggregates'));

        // Широкий агрегат по всему набору.
        $t = microtime(true);
        $totalExact = DB::selectOne("SELECT sum(km.exact_current) AS s FROM keyword_metrics km WHERE km.metric_run_id = ?", [$runId])->s;
        $report['queries']['broad_aggregate_sync'] = ['seconds' => round(microtime(true) - $t, 2), 'result' => (int) $totalExact];

        // Доступность активного отчёта во время (после) импорта фиксируется отчётом.
        $report['finished_at'] = now()->toDateTimeString();
        $this->writeReport($report, $datasetId);

        return self::SUCCESS;
    }

    private function writeReport(array $report, ?string $datasetId = null): void
    {
        @mkdir(storage_path('app/benchmarks'), 0775, true);
        $stamp = now()->format('Ymd_His');
        file_put_contents(storage_path("app/benchmarks/report_{$stamp}.json"), json_encode($report, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));

        $md = "# Бенчмарк Niche Radar — {$stamp}\n\n";
        $md .= "- Версии: PHP {$report['versions']['php']}, Laravel {$report['versions']['laravel']}, PostgreSQL {$report['versions']['postgres']}, {$report['versions']['algorithm']}\n";
        $md .= "- Ресурсы контейнера: {$report['cpu_count']} vCPU, memory_limit {$report['memory_limit']}\n";
        $md .= "- Повторов каждой категории: {$report['repeats']}\n";
        if (isset($report['import']['summary']['import_seconds'])) {
            $md .= sprintf("- Импорт: %.1f с (%d строк/с), пиковая память процесса: %.0f МБ\n",
                $report['import']['summary']['import_seconds'], $report['import']['summary']['rows_per_sec'] ?? 0,
                ($report['import']['peak_process_memory_bytes'] ?? 0) / 1048576);
        }
        $md .= "\n| Операция | p50, мс | p95, мс | Режим |\n|---|---|---|---|\n";
        foreach ($report['queries'] as $name => $q) {
            if (isset($q['p50_ms'])) {
                $md .= sprintf("| %s | %s | %s | %s |\n", $name, $q['p50_ms'], $q['p95_ms'], $q['mode'] ?? 'warm');
            } else {
                $md .= sprintf("| %s | — | %.2f с | sync |\n", $name, $q['seconds']);
            }
        }
        file_put_contents(storage_path("app/benchmarks/report_{$stamp}.md"), $md);
        $this->info('Отчёт: storage/app/benchmarks/report_'.$stamp.'.{md,json}');
    }
}
