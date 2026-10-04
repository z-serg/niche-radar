<?php

namespace App\Jobs;

use App\Domain\Datasets\ImportProcessor;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

/**
 * Тяжёлый импорт отчёта: очередь import, concurrency=1 (ТЗ §6.2, §12.1).
 * Попытка одна; ошибки фиксируются в import_jobs, а не в failed_jobs.
 */
class ImportDatasetJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 1;

    public int $timeout = 13800; // < retry_after соединения import (14400)

    public function __construct(
        public readonly string $datasetId,
        public readonly string $importJobId,
    ) {}

    public function handle(ImportProcessor $processor): void
    {
        $result = $processor->process($this->datasetId, $this->importJobId);

        Log::info('import.finished', [
            'dataset_id' => $this->datasetId,
            'job_id' => $this->importJobId,
            'status' => $result['status'],
        ]);
    }

    public function failed(\Throwable $e): void
    {
        // Страховка: любые необработанные сбои не оставляют отчёт «висеть».
        \Illuminate\Support\Facades\DB::table('import_jobs')
            ->where('id', $this->importJobId)
            ->whereIn('status', ['queued', 'running', 'cancelling'])
            ->update(['status' => 'failed', 'error' => 'JOB_FAILED: '.$e->getMessage(), 'finished_at' => now()]);

        \Illuminate\Support\Facades\DB::table('datasets')
            ->where('id', $this->datasetId)
            ->whereIn('status', ['validating', 'importing', 'calculating', 'queued'])
            ->update(['status' => 'failed', 'updated_at' => now()]);
    }
}
