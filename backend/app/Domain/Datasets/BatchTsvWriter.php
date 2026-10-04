<?php

namespace App\Domain\Datasets;

use Illuminate\Support\Facades\DB;

/**
 * Пакетный писатель TSV для COPY FROM STDIN: накапливает строки в файле
 * и по flush() закрывает его и загружает в staging-таблицу через
 * pgsqlCopyFromFile, затем открывает файл заново. Память ограничена
 * размером пакета, а не файла (ТЗ §6.1 п.10).
 */
class BatchTsvWriter
{
    private $handle;

    private int $pending = 0;

    public function __construct(
        private readonly string $path,
        private readonly string $table,
    ) {
        $this->handle = fopen($path, 'wb');
    }

    public function writeLine(string $line): void
    {
        fwrite($this->handle, $line);
        $this->pending++;
    }

    public function pending(): int
    {
        return $this->pending;
    }

    /**
     * Выгрузить накопленный файл в таблицу и перезапустить файл.
     */
    public function flush(): int
    {
        if ($this->pending === 0) {
            return 0;
        }
        fflush($this->handle);
        fclose($this->handle);

        $pdo = DB::connection()->getPdo();
        $ok = $pdo->pgsqlCopyFromFile($this->table, $this->path);
        if (! $ok) {
            throw new \RuntimeException("COPY в {$this->table} завершился с ошибкой.");
        }

        $this->handle = fopen($this->path, 'wb');
        $this->pending = 0;

        return 1;
    }

    public function close(): void
    {
        if (is_resource($this->handle)) {
            fclose($this->handle);
        }
    }
}
