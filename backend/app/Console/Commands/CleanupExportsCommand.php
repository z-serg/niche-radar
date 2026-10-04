<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Очистка просроченных экспортов (ТЗ §11: 7 дней по умолчанию).
 * Оригиналы и гипотезы автоматически не удаляются.
 */
class CleanupExportsCommand extends Command
{
    protected $signature = 'niche:cleanup-exports';

    protected $description = 'Удалить временные экспорты с истёкшим сроком';

    public function handle(): int
    {
        $expired = DB::table('export_jobs')
            ->where('status', 'done')
            ->whereNotNull('expires_at')
            ->where('expires_at', '<', now())
            ->get();

        foreach ($expired as $job) {
            if ($job->result_path) {
                @unlink(storage_path('app/'.$job->result_path));
            }
            DB::table('export_jobs')->where('id', $job->id)->delete();
        }

        // Остатки файлов без записей.
        $dir = storage_path('app/exports');
        if (is_dir($dir)) {
            foreach (glob($dir.'/*') ?: [] as $file) {
                $old = filemtime($file) < now()->subDays((int) config('niche.export_retention_days', 7) + 1)->getTimestamp();
                if ($old) {
                    @unlink($file);
                }
            }
        }

        $this->info('Удалено экспортов: '.count($expired));

        return self::SUCCESS;
    }
}
