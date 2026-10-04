<?php

namespace Tests\Integration;

use App\Domain\Datasets\DatasetManager;
use App\Domain\Datasets\ImportProcessor;
use App\Domain\Search\FilterPayload;
use App\Domain\Search\KeywordSearchService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Курсорная пагинация не пропускает и не повторяет строки, включая
 * одинаковые значения сортировки (ТЗ §14.2).
 */
class SearchPaginationTest extends TestCase
{
    use RefreshDatabase;

    public function test_pagination_covers_all_rows_without_duplicates(): void
    {
        @mkdir(storage_path('app/originals'), 0775, true);
        @mkdir(storage_path('app/staging'), 0775, true);

        // Выборка: много фраз с ОДИНАКОВЫМ приоритетом NULL/значением —
        // проверка вторичного ключа keyword_id.
        $header = '"#";"Предыдущий #";"Ключевое слово";"Слов";"Символов";"Частотность Весь мир";"Частотность Весь мир 6";"Частотность Весь мир 5";"Частотность Весь мир 4";"Частотность Весь мир 3";"Частотность Весь мир 2";"Частотность Весь мир 1";"""[!Частотность !Весь !мир]""";"""[!Частотность !Весь !мир]"" 6";"""[!Частотность !Весь !мир]"" 5";"""[!Частотность !Весь !мир]"" 4";"""[!Частотность !Весь !мир]"" 3";"""[!Частотность !Весь !мир]"" 2";"""[!Частотность !Весь !мир]"" 1"';
        $rows = '';
        for ($i = 1; $i <= 120; $i++) {
            // Все фразы с одинаковой частотностью → одинаковые метрики сортировки.
            // curB=b1=500; curE=e1=100 — текущие колонки согласованы с суффиксом 1.
            $rows .= "$i;$i;\"фраза номер $i для пагинации\";4;25;500;500;500;500;500;500;500;100;100;100;100;100;100;100\r\n";
        }
        $path = tempnam(sys_get_temp_dir(), 'pg_').'.csv';
        file_put_contents($path, "\xEF\xBB\xBF".$header."\r\n".$rows);

        $manager = app(DatasetManager::class);
        $ds = $manager->ingestFile($path, 'pagination.csv', []);
        $jobId = $manager->queueImport($ds['dataset_id']);
        $result = app(ImportProcessor::class)->process($ds['dataset_id'], $jobId);
        $this->assertSame('done', $result['status']);
        unlink($path);

        $service = app(KeywordSearchService::class);
        $datasetId = $ds['dataset_id'];

        $seen = [];
        $cursor = null;
        $pages = 0;
        do {
            $payload = new FilterPayload([
                'filters' => [],
                'sort' => [['field' => 'exact_current', 'direction' => 'desc']],
                'limit' => 7, // не делит 120 нацело
                'cursor' => $cursor,
            ]);
            $response = $service->search($datasetId, null, $payload);
            foreach ($response['data'] as $row) {
                $this->assertNotContains($row['keyword_id'], $seen, 'строка не повторяется');
                $seen[] = $row['keyword_id'];
            }
            $cursor = $response['next_cursor'];
            $pages++;
            $this->assertLessThan(30, $pages, 'пагинация зациклилась');
        } while ($cursor !== null);

        $this->assertCount(120, $seen, 'все строки выданы без пропусков');
        $this->assertSame(120, $service->count($datasetId, null, new FilterPayload([])));
    }
}
