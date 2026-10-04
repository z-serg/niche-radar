<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Поиск зависших заданий по heartbeat (ТЗ §6.2): переводятся в
 * восстанавливаемое состояние failed с понятной причиной.
 */
class CheckStaleJobsCommand extends Command
{
    protected $signature = 'niche:check-stale-jobs';

    protected $description = 'Пометить зависшие задания импорта/экспорта как failed';

    public function handle(): int
    {
        $minutes = (int) config('niche.stale_job_minutes', 15);
        $deadline = now()->subMinutes($minutes);

        $staleImports = DB::table('import_jobs')
            ->whereIn('status', ['running', 'queued'])
            ->where('heartbeat_at', '<', $deadline)
            ->get();

        foreach ($staleImports as $job) {
            DB::table('import_jobs')->where('id', $job->id)->update([
                'status' => 'failed',
                'error' => "STALE_HEARTBEAT: heartbeat старше {$minutes} мин; перезапустите через retry.",
                'finished_at' => now(),
                'updated_at' => now(),
            ]);
            DB::table('datasets')->where('id', $job->dataset_id)
                ->whereIn('status', ['validating', 'importing', 'calculating', 'queued'])
                ->update(['status' => 'failed', 'updated_at' => now()]);
        }

        $this->info("Зависших импортов помечено: ".count($staleImports));

        // Записи очереди, зарезервированные убитым/пересозданным воркером,
        // освобождаются после retry_after (4 ч > таймаут задания 3.8 ч, поэтому
        // живой длительный импорт задеть не может).
        $released = DB::table('jobs')
            ->where('queue', 'import')
            ->whereNotNull('reserved_at')
            ->where('reserved_at', '<', now()->subSeconds((int) config('queue.connections.import.retry_after')))
            ->update(['reserved_at' => null, 'attempts' => DB::raw('attempts')]);
        $this->info("Освобождено записей очереди import: {$released}");

        return self::SUCCESS;
    }
}
