<?php

namespace Tests\Unit;

use App\Domain\Datasets\BukvarixCsvAdapter;
use App\Support\CsvStreamer;
use PHPUnit\Framework\TestCase;

/**
 * Распознавание заголовков реального образца (ТЗ §4.3): имена после
 * CSV-разбора, вложенные кавычки точной частотности, перестановка колонок.
 */
class BukvarixAdapterTest extends TestCase
{
    private string $fixture;

    protected function setUp(): void
    {
        $this->fixture = __DIR__.'/../fixtures/Top10.csv';
    }

    private function mapFile(string $path): \App\Domain\Datasets\HeaderMap
    {
        return (new BukvarixCsvAdapter())->mapFile($path);
    }

    public function test_top10_headers_recognized(): void
    {
        $map = $this->mapFile($this->fixture);

        $this->assertTrue($map->isValid());
        $this->assertSame(6, $map->scanCount(), 'шесть уникальных замеров');
        $this->assertSame([], $map->unknown, 'все 19 колонок распознаны');

        // scans упорядочены от старого к новому: [0]=суффикс 6 … [5]=суффикс 1.
        $this->assertSame('6', $map->scans[0]['suffix'], 'ordinal 1 = суффикс 6 (самый старый)');
        $this->assertSame('1', $map->scans[5]['suffix'], 'новейший замер = суффикс 1');

        // Ненумерованные «текущие» колонки распознаны отдельно (дубли суффикса 1).
        $this->assertNotNull($map->currentBroadCol);
        $this->assertNotNull($map->currentExactCol);
    }

    public function test_exact_family_not_confused_with_broad(): void
    {
        $map = $this->mapFile($this->fixture);

        $exactRoles = array_filter($map->roles, fn ($r) => str_starts_with($r, 'exact'));
        $broadRoles = array_filter($map->roles, fn ($r) => str_starts_with($r, 'broad'));

        $this->assertCount(7, $exactRoles, '1 текущая + 6 исторических точных');
        $this->assertCount(7, $broadRoles);
    }

    public function test_permuted_columns_map_by_name(): void
    {
        // Читаем заголовок образца и переставляем колонки местами.
        $streamer = new CsvStreamer($this->fixture);
        $headers = $streamer->header();

        $order = [2, 0, 12, 5, 11, 4, 18, 10, 3, 9, 17, 8, 1, 7, 16, 6, 15, 14, 13];
        $permuted = array_map(fn ($i) => $headers[$i], $order);

        $map = (new BukvarixCsvAdapter())->map($permuted, ';');

        $this->assertTrue($map->isValid());
        $this->assertSame(6, $map->scanCount());
        // Колонка точной частотности с суффиксом 1 теперь в другой позиции.
        $this->assertSame(6, $map->scans[5]['exact_col']);
    }

    public function test_readme_alias_supported(): void
    {
        $headers = ['#', 'Предыдущий #', 'Ключевое слово', 'Слов', 'Символов',
            'Частотность "[!Весь !мир]"', '"""[!Частотность !Весь !мир]"""'];
        $parsed = array_map(fn ($h) => str_getcsv($h, ';', '"', '\\')[0], $headers);
        // Имитируем результат CSV-разбора: кавычки имён остаются частью значения.
        $parsed[5] = 'Частотность "[!Весь !мир]"';
        $parsed[6] = '"[!Частотность !Весь !мир]"';

        $map = (new BukvarixCsvAdapter())->map($parsed, ';');

        $this->assertNotNull($map->currentBroadCol);
        $this->assertNotNull($map->currentExactCol);
        $this->assertSame(1, $map->scanCount());
    }

    public function test_unknown_header_reported(): void
    {
        $map = (new BukvarixCsvAdapter())->map(['#', 'Непонятная колонка', 'Ключевое слово', 'Частотность Весь мир'], ';');

        $this->assertFalse($map->isValid());
        $this->assertArrayHasKey(1, $map->unknown);
    }
}
