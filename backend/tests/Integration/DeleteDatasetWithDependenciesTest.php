<?php

namespace Tests\Integration;

use App\Domain\Datasets\DatasetManager;
use App\Domain\Datasets\ImportProcessor;
use App\Domain\Niches\NicheEvaluator;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Удаление отчёта с зависимостями (ТЗ §8): блокировка со списком сущностей
 * и «перенос» — отвязка ссылок с сохранением снимков версий ниш и гипотез.
 */
class DeleteDatasetWithDependenciesTest extends TestCase
{
    use RefreshDatabase;

    public function test_delete_blocked_with_named_dependencies_then_detach_removes_dataset(): void
    {
        @mkdir(storage_path('app/originals'), 0775, true);
        @mkdir(storage_path('app/staging'), 0775, true);

        $manager = app(DatasetManager::class);
        $ds = $manager->ingestFile(__DIR__.'/../fixtures/Top10.csv', 'Top10.csv', []);
        $manager->queueImport($ds['dataset_id']);
        $result = app(ImportProcessor::class)->process($ds['dataset_id']);
        $this->assertSame('done', $result['status']);
        $datasetId = $ds['dataset_id'];

        // Ниша с версией и гипотеза с доказательством, привязанные к отчёту.
        $nicheId = (string) \Illuminate\Support\Str::uuid();
        DB::table('niches')->insert([
            'id' => $nicheId, 'name' => 'Конвертация тестовая', 'type' => 'fixed',
            'manual_include' => json_encode([1]), 'manual_exclude' => '[]',
            'created_at' => now(), 'updated_at' => now(),
        ]);
        $eval = app(NicheEvaluator::class)->evaluate($nicheId, $datasetId);
        $hypId = (string) \Illuminate\Support\Str::uuid();
        DB::table('hypotheses')->insert([
            'id' => $hypId, 'title' => 'Тестовая гипотеза удаления', 'status' => 'candidate',
            'dataset_id' => $datasetId, 'created_at' => now(), 'updated_at' => now(),
        ]);
        DB::table('hypothesis_evidence')->insert([
            'hypothesis_id' => $hypId, 'keyword_id' => 1, 'dataset_id' => $datasetId,
            'snapshot' => json_encode(['phrase' => 'тест', 'metrics' => ['exact_current' => 1]]),
            'created_at' => now(),
        ]);

        // --- Без detach: блокировка с человекочитаемым списком.
        $blocked = $manager->delete($datasetId);
        $this->assertFalse($blocked['deleted']);
        $names = array_column($blocked['items'], 'name');
        $this->assertContains('Конвертация тестовая', $names);
        $this->assertContains('Тестовая гипотеза удаления', $names);
        $this->assertNotNull(DB::table('datasets')->where('id', $datasetId)->first(), 'отчёт не удалён');

        // REST: 409 с items (локальное приложение, без аутентификации).
        $response = $this->deleteJson("/api/v1/datasets/{$datasetId}");
        $response->assertStatus(409)
            ->assertJsonPath('code', 'DATASET_HAS_DEPENDENCIES');
        $this->assertNotEmpty($response->json('items'));

        // --- С detach: отчёт удалён, снимки сохранены.
        $deleted = $manager->delete($datasetId, detach: true);
        $this->assertTrue($deleted['deleted']);
        $this->assertNull(DB::table('datasets')->where('id', $datasetId)->first());

        $version = DB::table('niche_versions')->where('id', $eval['niche_version_id'])->first();
        $this->assertNotNull($version, 'версия ниши сохранена как история');
        $this->assertNull($version->dataset_id, 'ссылка версии на отчёт снята');
        $this->assertNotNull($version->aggregates, 'снимок агрегатов цел');

        $hypothesis = DB::table('hypotheses')->where('id', $hypId)->first();
        $this->assertNotNull($hypothesis, 'гипотеза сохранена');
        $this->assertNull($hypothesis->dataset_id, 'ссылка гипотезы снята');

        $evidence = DB::table('hypothesis_evidence')->where('hypothesis_id', $hypId)->get();
        $this->assertCount(1, $evidence, 'доказательство сохранено');
        $this->assertNull($evidence[0]->dataset_id);
        $this->assertSame(0, (int) DB::table('source_files')->count(), 'оригинал удалён вместе с отчётом');
    }
}
