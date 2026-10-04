<?php

namespace App\Jobs;

use App\Domain\Analytics\SummaryService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

/**
 * Отложенный широкий агрегат (ТЗ §9: 202 + job_id).
 */
class AnalyticsSummaryJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 1;

    public function __construct(public readonly string $analyticsJobId) {}

    public function handle(SummaryService $service): void
    {
        $service->processJob($this->analyticsJobId);
    }
}
