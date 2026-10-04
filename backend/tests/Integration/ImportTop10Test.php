<?php

namespace Tests\Integration;

use App\Domain\Datasets\DatasetManager;
use App\Domain\Datasets\ImportProcessor;
use App\Domain\Search\Presets;
use App\Domain\Metrics\MetricRunService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Импорт реального образца Top10.csv (ТЗ §14.1).
 */
class ImportTop10Test extends TestCase
{
    use RefreshDatabase;

    private string $datasetId;

    protected function setUp(): void
    {
        parent::setUp();
        @mkdir(storage_path('app/originals'), 0775, true);
        @mkdir(storage_path('app/staging'), 0775, true);
    }

    private function importSample(): string
    {
        $manager = app(DatasetManager::class);
        $result = $manager->ingestFile(__DIR__.'/../fixtures/Top10.csv', 'Top10.csv', [
            'coverage_type' => 'sample',
        ]);
        $this->datasetId = $result['dataset_id'];
        $manager->queueImport($this->datasetId);

        $job = app(ImportProcessor::class)->process($this->datasetId);

        $this->assertSame('done', $job['status'], 'импорт завершается успешно: '.json_encode($job));

        return $this->datasetId;
    }

    public function test_top10_import_counts_and_control_values(): void
    {
        $datasetId = $this->importSample();

        // 10 фраз, 10 записей dataset_keywords, 6 замеров, 60 наблюдений.
        $this->assertSame(10, (int) DB::table('keywords')->count());
        $this->assertSame(10, (int) DB::table('dataset_keywords')->where('dataset_id', $datasetId)->count());
        $this->assertSame(6, (int) DB::table('dataset_scans')->where('dataset_id', $datasetId)->count());
        $this->assertSame(60, (int) DB::selectOne(
            'SELECT count(*) AS n FROM observations WHERE scan_id IN (SELECT id FROM dataset_scans WHERE dataset_id = ?)',
            [$datasetId]
        )->n);

        // Ранги 2..11 сохранены без перенумерации (ТЗ §4.3).
        $ranks = DB::table('dataset_keywords')->where('dataset_id', $datasetId)->orderBy('rank_current')->pluck('rank_current')->all();
        $this->assertSame(range(2, 11), array_map('intval', $ranks));

        // Контрольная строка «переводчик» (ТЗ §4.3).
        $translator = DB::table('keywords')->where('phrase_original', 'переводчик')->first();
        $this->assertNotNull($translator);
        $current = DB::selectOne("
            SELECT o.exact, o.broad FROM observations o
            JOIN dataset_scans s ON s.id = o.scan_id
            WHERE s.dataset_id = ? AND s.ordinal = 6 AND o.keyword_id = ?
        ", [$datasetId, $translator->id]);
        $this->assertSame(21466358, (int) $current->exact);
        $this->assertSame(34635675, (int) $current->broad);

        // Полная история точных от старого к новому.
        $history = DB::select("
            SELECT o.exact FROM observations o
            JOIN dataset_scans s ON s.id = o.scan_id
            WHERE s.dataset_id = ? AND o.keyword_id = ?
            ORDER BY s.ordinal
        ", [$datasetId, $translator->id]);
        $this->assertSame([24704716, 24993362, 25094186, 24831529, 25726636, 21466358], array_map(fn ($h) => (int) $h->exact, $history));

        // Исходные длины совпадают с вычисленными (для «переводчик»: 1 слово, 10 символов).
        $dk = DB::table('dataset_keywords')->where('dataset_id', $datasetId)->where('keyword_id', $translator->id)->first();
        $this->assertSame(1, (int) $dk->word_count_source);
        $this->assertSame(10, (int) $dk->char_count_source);
        $this->assertSame(1, $translator->word_count);
        $this->assertSame(10, $translator->char_count);

        // Контрольный пример резкого изменения: C = 0.4, не проходит пресет.
        $champ = DB::table('keywords')->where('phrase_original', 'чемпионат мира матчи')->first();
        $runId = app(MetricRunService::class)->defaultRunId($datasetId);
        $km = DB::table('keyword_metrics')->where('metric_run_id', $runId)->where('keyword_id', $champ->id)->first();
        $this->assertSame(14868825, (int) $km->exact_current);
        $this->assertEqualsWithDelta(0.4, (float) $km->consistency, 1e-9);
        $passes = \App\Domain\Search\Presets::conditions('sustained_growth', 'km');
        $passesSustained = DB::table('keyword_metrics as km')->where('km.metric_run_id', $runId)->where('km.keyword_id', $champ->id)
            ->whereRaw($passes)->exists();
        $this->assertFalse($passesSustained, '«чемпионат мира матчи» не проходит пресет устойчивого роста');

        // Пресет устойчивого роста на всём образце: 0 из 10 строк (ТЗ §14.1).
        $total = DB::table('keyword_metrics as km')->where('km.metric_run_id', $runId)->whereRaw($passes)->count();
        $this->assertSame(0, $total);

        // Пресеты «Лидеры роста/падения»: полный ряд без порогов, сортировка по A
        // — как одноимённые карточки дашборда. На образце все 10 фраз с полным рядом.
        $leaders = DB::table('keyword_metrics as km')
            ->join('keywords as k', 'k.id', '=', 'km.keyword_id')
            ->where('km.metric_run_id', $runId)
            ->whereRaw(Presets::conditions('growth_leaders', 'km'))
            ->orderByDesc('km.smoothed_delta')->orderBy('km.keyword_id')
            ->pluck('k.phrase_original');
        $this->assertCount(10, $leaders);
        $this->assertSame('чемпионат мира матчи', $leaders[0], 'максимальный сглаженный прирост');

        $fallers = DB::table('keyword_metrics as km')
            ->join('keywords as k', 'k.id', '=', 'km.keyword_id')
            ->where('km.metric_run_id', $runId)
            ->whereRaw(Presets::conditions('fall_leaders', 'km'))
            ->orderBy('km.smoothed_delta')->orderBy('km.keyword_id')
            ->pluck('k.phrase_original');
        $this->assertSame('переводчик', $fallers[0], 'минимальный сглаженный прирост');
    }

    public function test_double_upload_same_file_does_not_duplicate(): void
    {
        $manager = app(DatasetManager::class);
        $first = $manager->ingestFile(__DIR__.'/../fixtures/Top10.csv', 'Top10.csv', []);
        $second = $manager->ingestFile(__DIR__.'/../fixtures/Top10.csv', 'Top10-copy.csv', []);

        $this->assertTrue($second['existing']);
        $this->assertSame($first['dataset_id'], $second['dataset_id']);
        $this->assertSame(1, (int) DB::table('datasets')->count());
        $this->assertSame(1, (int) DB::table('source_files')->count());
    }

    public function test_retry_after_reimport_is_idempotent(): void
    {
        $datasetId = $this->importSample();

        // Повторный запуск не создаёт дублей наблюдений (ТЗ §6.2, §14.2).
        app(ImportProcessor::class)->process($datasetId);

        $this->assertSame(10, (int) DB::table('dataset_keywords')->where('dataset_id', $datasetId)->count());
        $this->assertSame(60, (int) DB::selectOne(
            'SELECT count(*) AS n FROM observations WHERE scan_id IN (SELECT id FROM dataset_scans WHERE dataset_id = ?)',
            [$datasetId]
        )->n);
    }

    public function test_reimport_with_evaluated_niche_keeps_version_and_succeeds(): void
    {
        $datasetId = $this->importSample();

        // Ниша с оценённой версией до повторного импорта.
        $nicheId = (string) \Illuminate\Support\Str::uuid();
        DB::table('niches')->insert([
            'id' => $nicheId, 'name' => 'Ниша до переимпорта', 'type' => 'fixed',
            'manual_include' => json_encode([1, 2]), 'manual_exclude' => '[]',
            'created_at' => now(), 'updated_at' => now(),
        ]);
        $eval = app(\App\Domain\Niches\NicheEvaluator::class)->evaluate($nicheId, $datasetId);
        $versionId = $eval['niche_version_id'];
        $this->assertSame(1, $eval['version']);

        // Регрессия: повторный импорт пересоздаёт metric_runs и раньше падал
        // по NOT NULL на niche_versions.metric_run_id.
        $job = app(ImportProcessor::class)->process($datasetId);
        $this->assertSame('done', $job['status'], 'повторный импорт не падает при существующих нишах');

        // Версия ниши сохранена; ссылка на удалённый прогон погашена, снимок агрегатов цел.
        $version = DB::table('niche_versions')->where('id', $versionId)->first();
        $this->assertNotNull($version);
        $this->assertNull($version->metric_run_id);
        $this->assertNotNull($version->aggregates);
        $this->assertSame('ready', DB::table('datasets')->where('id', $datasetId)->value('status'));
    }
}
