<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

// Планировщик (контейнер scheduler, ТЗ §12.1).
Schedule::command('niche:cleanup-exports')->daily();
Schedule::command('niche:check-stale-jobs')->everyFiveMinutes();
