<?php

namespace App\Support;

use RuntimeException;

/**
 * Потоковый CSV-парсер (RFC 4180 + CRLF): кавычки, удвоенные кавычки,
 * переносы строк внутри полей, разделители `;`, `,`, TAB, UTF-8 c BOM и без.
 *
 * Файл не разбивается split'ом по переводам строк (ТЗ §6.1 п.3): строки
 * с нечётным числом кавычек считаются продолжением записи. Невалидный
 * UTF-8 не исправляется молча — вызывается колбэк ошибки.
 */
class CsvStreamer
{
    private $handle;

    private string $delimiter = ';';

    private bool $delimiterDetected = false;

    private bool $bomStripped = false;

    private string $eol = "\n";

    private int $lineNo = 0;

    public function __construct(
        private readonly string $path,
        private readonly array $delimiters = [';', "\t", ','],
    ) {
        $this->handle = fopen($path, 'rb');
        if ($this->handle === false) {
            throw new RuntimeException("Не удалось открыть файл: {$path}");
        }
    }

    public function __destruct()
    {
        if (is_resource($this->handle)) {
            fclose($this->handle);
        }
    }

    public function delimiter(): string
    {
        if (! $this->delimiterDetected) {
            $this->detectDelimiter();
        }

        return $this->delimiter;
    }

    /**
     * Определение разделителя по первым строкам: выбирается разделитель,
     * дающий наибольшее число колонок вне кавычек (минимум 2).
     */
    public function detectDelimiter(): string
    {
        rewind($this->handle);
        $this->bomStripped = false;
        $this->stripBomIfNeeded();

        $best = null;
        $bestCount = 1;
        for ($i = 0; $i < 5; $i++) {
            $line = $this->readRawLine();
            if ($line === null || $line === '') {
                break;
            }
            foreach ($this->delimiters as $d) {
                $count = $this->countOutsideQuotes($line, $d) + 1;
                if ($count > $bestCount) {
                    $bestCount = $count;
                    $best = $d;
                }
            }
        }

        if ($best === null) {
            throw new RuntimeException('Не удалось определить разделитель CSV: ожидается `;`, `,` или TAB.');
        }

        $this->delimiter = $best;
        $this->delimiterDetected = true;

        return $best;
    }

    /**
     * Заголовок файла (после стандартного CSV-разбора: вложенные кавычки
     * остаются частью имён, ТЗ §4.3).
     *
     * @return string[]
     */
    public function header(): array
    {
        $this->detectDelimiter();
        rewind($this->handle);
        $this->bomStripped = false;
        $this->stripBomIfNeeded();
        $line = $this->readRawLine();
        if ($line === null) {
            throw new RuntimeException('Файл не содержит заголовка.');
        }

        return str_getcsv($line, $this->delimiter, '"', '\\');
    }

    /**
     * Итератор записей. Каждый элемент — ['record_no' => int, 'fields' => string[]].
     * Колбэк onInvalidUtf8 вызывается вместо молчаливой правки; если он
     * вернёт true, запись пропускается.
     *
     * @param  callable(string, int): bool|null  $onInvalidUtf8
     */
    public function records(?callable $onInvalidUtf8 = null): \Generator
    {
        $this->detectDelimiter();
        rewind($this->handle);
        $this->bomStripped = false;
        $this->stripBomIfNeeded();
        $this->lineNo = 0;
        $recordNo = 0;
        $buffer = '';
        $bufferLines = 0;

        while (($line = $this->readRawLine()) !== null) {
            $this->lineNo++;
            $buffer = $buffer === '' ? $line : $buffer.$this->eol.$line;
            $bufferLines++;

            $quotes = substr_count($buffer, '"');
            if ($quotes % 2 === 1) {
                // Нечётное число кавычек — запись продолжается на следующих строках.
                continue;
            }

            $recordNo++;
            $fields = str_getcsv($buffer, $this->delimiter, '"', '\\');
            $buffer = '';
            $bufferLines = 0;

            $invalid = false;
            foreach ($fields as $field) {
                if (! mb_check_encoding($field, 'UTF-8')) {
                    $invalid = true;
                    break;
                }
            }
            if ($invalid) {
                if ($onInvalidUtf8 === null || $onInvalidUtf8($buffer, $recordNo) !== true) {
                    throw new RuntimeException(
                        "Невалидный UTF-8 в записи {$recordNo} (строка {$this->lineNo}). ".
                        'Укажите кодировку или загрузите исправленный файл.'
                    );
                }
                continue;
            }

            yield ['record_no' => $recordNo, 'fields' => $fields];
        }

        if ($buffer !== '' && $bufferLines > 0) {
            throw new RuntimeException("Незавершённая запись в конце файла (строка {$this->lineNo}).");
        }
    }

    public function fileSize(): int
    {
        $size = filesize($this->path);

        return $size === false ? 0 : $size;
    }

    public function tell(): int
    {
        $pos = ftell($this->handle);

        return $pos === false ? 0 : $pos;
    }

    private function readRawLine(): ?string
    {
        $line = stream_get_line($this->handle, 1 << 22, "\n");
        if ($line === false) {
            return null;
        }
        // Фиксируем EOL файла один раз и нормализуем хвостовой \r.
        if (str_ends_with($line, "\r")) {
            $this->eol = "\r\n";
            $line = substr($line, 0, -1);
        } else {
            $this->eol = "\n";
        }

        return $line;
    }

    private function stripBomIfNeeded(): void
    {
        if ($this->bomStripped || ftell($this->handle) !== 0) {
            return;
        }
        $bom = fread($this->handle, 3);
        if ($bom !== "\xEF\xBB\xBF") {
            rewind($this->handle);
        }
        $this->bomStripped = true;
    }

    private function countOutsideQuotes(string $line, string $delimiter): int
    {
        $inQuotes = false;
        $count = 0;
        $len = strlen($line);
        for ($i = 0; $i < $len; $i++) {
            $ch = $line[$i];
            if ($ch === '"') {
                $inQuotes = ! $inQuotes;
            } elseif ($ch === $delimiter && ! $inQuotes) {
                $count++;
            }
        }

        return $count;
    }
}
