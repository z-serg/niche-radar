<?php

namespace App\Console\Commands;

use App\Domain\Rules\RuleSetRegistry;
use Illuminate\Console\Command;

/**
 * Идемпотентное заполнение стартовых наборов правил (ТЗ §12.2):
 * повторный запуск не дублирует и не изменяет существующие данные.
 */
class SeedRulesCommand extends Command
{
    protected $signature = 'niche:seed-rules';

    protected $description = 'Заполнить стартовые наборы правил (идемпотентно)';

    public function handle(): int
    {
        RuleSetRegistry::seedDefaults();
        $this->info('Стартовые наборы правил актуальны.');

        return self::SUCCESS;
    }
}
