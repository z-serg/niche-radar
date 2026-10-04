<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;

class ImportStatusCommand extends Command
{
    protected $signature = 'niche:import-status {jobId}';

    protected $description = 'Показать статус/прогресс задания импорта';

    public function handle(): int
    {
        $job = \Illuminate\Support\Facades\DB::table('import_jobs')->where('id', $this->argument('jobId'))->first();
        if ($job === null) {
            $this->error('Задание не найдено.');

            return self::FAILURE;
        }
        $this->line(json_encode([
            'status' => $job->status,
            'stage' => $job->stage,
            'progress' => json_decode((string) $job->progress, true),
            'heartbeat_at' => $job->heartbeat_at,
            'error' => $job->error,
        ], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));

        return self::SUCCESS;
    }
}
