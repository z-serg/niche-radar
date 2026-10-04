<?php

namespace App\Console\Commands;

use App\Domain\Datasets\DatasetManager;
use Illuminate\Console\Command;

/**
 * CLI-импорт локального файла без ограничений HTTP (ТЗ §12.2):
 * ./bin/import /absolute/path/report.csv — тот же импорт, что в UI.
 */
class ImportCommand extends Command
{
    protected $signature = 'niche:import
        {path : абсолютный путь к CSV}
        {--coverage=unknown : unknown|full_top|sample|filtered}
        {--title= : название отчёта}
        {--skip-invalid : пропускать ошибочные записи}
        {--wait : ждать завершения импорта}';

    protected $description = 'Принять CSV-отчёт и поставить импорт в очередь';

    public function handle(DatasetManager $manager): int
    {
        $path = realpath($this->argument('path'));
        if ($path === false || ! is_file($path)) {
            $this->error('Файл не найден: '.$this->argument('path'));

            return self::FAILURE;
        }

        $result = $manager->ingestFile($path, basename($path), [
            'title' => $this->option('title'),
            'coverage_type' => $this->option('coverage'),
            'policy' => ['skip_invalid' => (bool) $this->option('skip-invalid')],
        ]);

        if ($result['existing']) {
            $this->info('Файл с таким SHA-256 уже принят; возвращён существующий отчёт: '.$result['dataset_id']);

            return self::SUCCESS;
        }

        $jobId = $manager->queueImport($result['dataset_id']);
        $this->info("Отчёт создан: {$result['dataset_id']}");
        $this->info("Импорт поставлен в очередь: {$jobId}");
        $this->line("Статус: php artisan niche:import-status {$jobId} или docker compose logs worker-import");

        if (! $this->option('wait')) {
            return self::SUCCESS;
        }

        // Ожидание завершения (полезно для тестов и скриптов).
        $prev = '';
        while (true) {
            $job = \Illuminate\Support\Facades\DB::table('import_jobs')->where('id', $jobId)->first();
            $line = sprintf('%s %s', $job->status, $job->stage);
            if ($line !== $prev) {
                $this->line('['.now()->toTimeString().'] '.$line);
                $prev = $line;
            }
            if (in_array($job->status, ['done', 'failed', 'cancelled'], true)) {
                if ($job->error) {
                    $this->error($job->error);
                }

                return $job->status === 'done' ? self::SUCCESS : self::FAILURE;
            }
            sleep(2);
        }
    }
}
