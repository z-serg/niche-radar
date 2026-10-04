<?php

namespace Tests\Integration;

use App\Domain\Datasets\DatasetManager;
use App\Domain\Datasets\ImportProcessor;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Расхождение ненумерованной текущей колонки с колонкой суффикса 1
 * блокирует публикацию до выбора владельцем основной колонки (ТЗ §4.2, §14.1).
 */
class CurrentColumnsMismatchTest extends TestCase
{
    use RefreshDatabase;

    private string $csv;

    protected function setUp(): void
    {
        parent::setUp();
        @mkdir(storage_path('app/originals'), 0775, true);
        @mkdir(storage_path('app/staging'), 0775, true);

        // Валидная схема; в 1-й записи ненумерованная широкая (колонка 6) != суффиксу 1.
        $header = '"#";"Предыдущий #";"Ключевое слово";"Слов";"Символов";"Частотность Весь мир";"Частотность Весь мир 6";"Частотность Весь мир 5";"Частотность Весь мир 4";"Частотность Весь мир 3";"Частотность Весь мир 2";"Частотность Весь мир 1";"""[!Частотность !Весь !мир]""";"""[!Частотность !Весь !мир]"" 6";"""[!Частотность !Весь !мир]"" 5";"""[!Частотность !Весь !мир]"" 4";"""[!Частотность !Весь !мир]"" 3";"""[!Частотность !Весь !мир]"" 2";"""[!Частотность !Весь !мир]"" 1"';
        $row = fn ($cur, $b6, $b5, $b4, $b3, $b2, $b1, $e6, $e5, $e4, $e3, $e2, $e1) =>
            "1;1;\"тест фраза $e1\";2;10;$cur;$b6;$b5;$b4;$b3;$b2;$b1;$e1;$e6;$e5;$e4;$e3;$e2;$e1";
        // Ненумерованная широкая = 999, суффикс-1 широкая = 1000 → расхождение.
        $this->csv = "\xEF\xBB\xBF".$header."\r\n"
            .$row(999, 10, 12, 14, 16, 18, 1000, 10, 12, 14, 16, 18, 50)."\r\n"
            .$row(70, 10, 12, 14, 16, 18, 70, 10, 12, 14, 16, 18, 70)."\r\n";
    }

    public function test_mismatch_blocks_publication_until_owner_chooses(): void
    {
        $path = tempnam(sys_get_temp_dir(), 'mm_').'.csv';
        file_put_contents($path, $this->csv);

        $manager = app(DatasetManager::class);
        $ds = $manager->ingestFile($path, 'mismatch.csv', []);
        $jobId = $manager->queueImport($ds['dataset_id']);
        $result = app(ImportProcessor::class)->process($ds['dataset_id'], $jobId);

        // Публикация заблокирована, отчёт в needs_mapping, факт расхождения сохранён.
        $this->assertSame('blocked', $result['status']);
        $dataset = DB::table('datasets')->where('id', $ds['dataset_id'])->first();
        $this->assertSame('needs_mapping', $dataset->status);
        $this->assertContains('current_columns_mismatch', json_decode($dataset->quality_flags, true));
        $this->assertStringContainsString(
            'CURRENT_COLUMNS_MISMATCH',
            (string) DB::table('import_jobs')->where('id', $jobId)->value('error')
        );

        // Владелец выбирает основную колонку (suffixed) → импорт проходит с предупреждением.
        $manager->setPolicy($ds['dataset_id'], ['authoritative_current' => 'suffixed']);
        $jobId2 = $manager->queueImport($ds['dataset_id']);
        $result2 = app(ImportProcessor::class)->process($ds['dataset_id'], $jobId2);

        $this->assertSame('done', $result2['status']);
        $dataset2 = DB::table('datasets')->where('id', $ds['dataset_id'])->first();
        $this->assertSame('ready', $dataset2->status);
        $this->assertNotEmpty($dataset2->quality_flags, 'факт расхождения сохраняется как предупреждение');

        // Значение новейшего замера взято из пронумерованной колонки (1000, не 999).
        $latestBroad = DB::selectOne("
            SELECT o.broad FROM observations o
            JOIN dataset_scans s ON s.id = o.scan_id
            WHERE s.dataset_id = ? AND s.ordinal = 6 AND o.broad IS NOT NULL
            ORDER BY o.keyword_id LIMIT 1
        ", [$ds['dataset_id']]);
        $this->assertSame(1000, (int) $latestBroad->broad);

        unlink($path);
    }
}
