<?php

namespace App\Jobs;

use App\Domain\Metrics\MetricRunService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

/**
 * Расчёт произвольного прогона метрик (диапазон/профиль по запросу, ТЗ §8).
 */
class ComputeMetricRunJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 1;

    public int $timeout = 13800;

    public function __construct(public readonly string $metricRunId) {}

    public function handle(MetricRunService $service): void
    {
        $service->compute($this->metricRunId);
    }
}
