<?php

namespace Tests\Unit;

use App\Support\CsvStreamer;
use PHPUnit\Framework\TestCase;

class CsvParserTest extends TestCase
{
    private string $tmp;

    protected function setUp(): void
    {
        $this->tmp = tempnam(sys_get_temp_dir(), 'csv_');
    }

    protected function tearDown(): void
    {
        @unlink($this->tmp);
    }

    private function write(string $content): void
    {
        file_put_contents($this->tmp, $content);
    }

    public function test_bom_and_crlf_and_semicolon(): void
    {
        $this->write("\xEF\xBB\xBF\"#\";\"Фраза\"\r\n1;\"привет\"\r\n2;\"пока\"\r\n");

        $s = new CsvStreamer($this->tmp);
        $this->assertSame(';', $s->detectDelimiter());
        $this->assertSame(['#', 'Фраза'], $s->header());

        // records() выдаёт все записи, включая заголовок (импортер пропускает его сам).
        $records = iterator_to_array($s->records());
        $this->assertSame([['#', 'Фраза'], ['1', 'привет'], ['2', 'пока']], array_map(fn ($r) => $r['fields'], $records));
    }

    public function test_embedded_newline_and_semicolon_and_quotes(): void
    {
        $line = '3;"фраза с ""кавычками"", точкой; с запятой
и переносом строки";7'.PHP_EOL;

        $this->write($line);
        $s = new CsvStreamer($this->tmp);
        $records = iterator_to_array($s->records());
        $fields = $records[0]['fields'];

        $this->assertSame('фраза с "кавычками", точкой; с запятой'."\n".'и переносом строки', $fields[1]);
        $this->assertSame('7', $fields[2]);
    }

    public function test_tab_delimiter_detected(): void
    {
        $this->write("a\tb\tc\n1\t2\t3\n");
        $s = new CsvStreamer($this->tmp);
        $this->assertSame("\t", $s->detectDelimiter());
    }

    public function test_comma_delimiter_detected(): void
    {
        $this->write("a,b,c\n1,2,3\n");
        $s = new CsvStreamer($this->tmp);
        $this->assertSame(',', $s->detectDelimiter());
    }

    public function test_invalid_utf8_raises(): void
    {
        $this->write("a;b\n\"\xB1\xB2\";2\n"); // невалидная UTF-8 последовательность
        $s = new CsvStreamer($this->tmp);
        $s->detectDelimiter();

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('UTF-8');
        iterator_to_array($s->records());
    }

    public function test_invalid_utf8_skip_callback(): void
    {
        $this->write("a;b\n\"\xB1\xB2\";2\n3;4\n");
        $s = new CsvStreamer($this->tmp);
        $records = iterator_to_array($s->records(onInvalidUtf8: fn () => true));

        // Заголовок + валидная запись; невалидная пропущена колбэком.
        $this->assertCount(2, $records);
        $this->assertSame('3', $records[1]['fields'][0]);
    }
}
