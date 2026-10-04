<?php

namespace Tests\Integration;

use App\Domain\Datasets\DatasetManager;
use App\Domain\Datasets\ImportProcessor;
use App\Domain\Exports\ExportProcessor;
use App\Domain\Niches\NicheEvaluator;
use App\Domain\Search\FilterPayload;
use App\Domain\Search\KeywordSearchService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * E2E-сценарий ТЗ §14.2: импорт → фильтры → ниша → гипотеза → экспорт.
 */
class E2eSmokeTest extends TestCase
{
    use RefreshDatabase;

    public function test_full_workflow(): void
    {
        @mkdir(storage_path('app/originals'), 0775, true);
        @mkdir(storage_path('app/staging'), 0775, true);

        // --- 1. Импорт образца ---
        $manager = app(DatasetManager::class);
        $ds = $manager->ingestFile(__DIR__.'/../fixtures/Top10.csv', 'Top10.csv', ['coverage_type' => 'sample']);
        $jobId = $manager->queueImport($ds['dataset_id']);
        $result = app(ImportProcessor::class)->process($ds['dataset_id'], $jobId);
        $this->assertSame('done', $result['status']);
        $manager->activate($ds['dataset_id']);

        // --- 2. Фильтры: токенный поиск ---
        $search = app(KeywordSearchService::class);
        $response = $search->search($ds['dataset_id'], null, new FilterPayload([
            'filters' => ['include' => ['terms' => ['чемпионат'], 'match' => 'token']],
            'limit' => 50,
        ]));
        $this->assertCount(2, $response['data']);
        $this->assertSame('ready', DB::table('datasets')->where('id', $ds['dataset_id'])->value('status'));

        // --- 3. Ниша (фиксированная) и оценка ---
        $ids = array_column($response['data'], 'keyword_id');
        $nicheId = (string) \Illuminate\Support\Str::uuid();
        DB::table('niches')->insert([
            'id' => $nicheId, 'name' => 'Чемпионат мира', 'type' => 'fixed',
            'manual_include' => json_encode($ids), 'manual_exclude' => '[]',
            'created_at' => now(), 'updated_at' => now(),
        ]);
        $eval = app(NicheEvaluator::class)->evaluate($nicheId, $ds['dataset_id']);
        $this->assertSame(2, $eval['member_count']);
        $this->assertSame(100.0, $eval['coverage_pct']);
        $this->assertArrayHasKey('dynamics', $eval['aggregates']);

        // --- 4. Гипотеза с доказательством (снимок значений) ---
        $hypId = (string) \Illuminate\Support\Str::uuid();
        DB::table('hypotheses')->insert([
            'id' => $hypId, 'title' => 'Тестовая гипотеза', 'status' => 'candidate',
            'dataset_id' => $ds['dataset_id'],
            'created_at' => now(), 'updated_at' => now(),
        ]);
        $first = $response['data'][0];
        DB::table('hypothesis_evidence')->insert([
            'hypothesis_id' => $hypId, 'keyword_id' => $first['keyword_id'],
            'dataset_id' => $ds['dataset_id'],
            'snapshot' => json_encode(['phrase' => $first['phrase'], 'metrics' => $first['metrics']], JSON_UNESCAPED_UNICODE),
            'created_at' => now(),
        ]);

        // --- 5. Экспорт CSV выборки и Markdown гипотезы (синхронно) ---
        $searchExport = ExportProcessor::queue('search_csv', [
            'dataset_id' => $ds['dataset_id'],
            'filters' => ['include' => ['terms' => ['чемпионат'], 'match' => 'token']],
            'format' => 'tables',
        ]);
        $processor = app(ExportProcessor::class);
        $ref = new \ReflectionMethod($processor, 'process');
        $ref->invoke($processor, $searchExport);

        $exportJob = DB::table('export_jobs')->where('id', $searchExport)->first();
        $this->assertSame('done', $exportJob->status);
        $csv = file_get_contents(storage_path('app/'.$exportJob->result_path));
        $this->assertStringStartsWith("\xEF\xBB\xBF", $csv, 'UTF-8 BOM');
        $this->assertStringContainsString('чемпионат', $csv);

        $mdExport = ExportProcessor::queue('hypothesis_md', ['hypothesis_id' => $hypId]);
        $ref->invoke($processor, $mdExport);
        $mdJob = DB::table('export_jobs')->where('id', $mdExport)->first();
        $md = file_get_contents(storage_path('app/'.$mdJob->result_path));
        $this->assertStringContainsString('# Гипотеза: Тестовая гипотеза', $md);
        $this->assertStringContainsString('Подтверждающие фразы', $md);

        // Локальное приложение: REST доступен без аутентификации.
        $this->getJson('/api/v1/datasets')->assertStatus(200)->assertJsonCount(1, 'data');
    }
}
