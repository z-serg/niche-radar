<?php

namespace App\Jobs;

use App\Domain\Exports\ExportProcessor;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

/**
 * Фоновый экспорт: очередь default, отдельный worker от импорта (ТЗ §6.2).
 */
class ExportJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 1;

    public int $timeout = 840; // < retry_after default (900)

    public function __construct(public readonly string $exportJobId) {}

    public function handle(ExportProcessor $processor): void
    {
        $processor->process($this->exportJobId);
    }
}
