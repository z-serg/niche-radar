<?php

namespace App\Domain\Rules;

use Illuminate\Support\Facades\DB;

/**
 * Реестр версионированных наборов правил. Активный набор хранится в БД
 * (rule_sets); файлы в app/Domain/Rules — исходные версии для сидирования.
 */
final class RuleSetRegistry
{
    public static function activeTaskSignals(): array
    {
        $row = DB::table('rule_sets')
            ->where('kind', 'task_signals')
            ->where('is_active', true)
            ->orderByDesc('created_at')
            ->first();

        if ($row !== null) {
            $config = json_decode($row->config, true);

            return $config + ['_db_id' => $row->id, '_db_version' => $row->version];
        }

        // Фолбэк на встроенный набор (до сидирования).
        $config = require __DIR__.'/task_signals_v1.php';

        return $config + ['_db_id' => null, '_db_version' => $config['version']];
    }

    public static function activeStopwords(): array
    {
        $row = DB::table('rule_sets')
            ->where('kind', 'stopwords')
            ->where('is_active', true)
            ->orderByDesc('created_at')
            ->first();

        if ($row !== null) {
            return json_decode($row->config, true) + ['_db_id' => $row->id];
        }

        $config = require __DIR__.'/stopwords_v1.php';

        return $config + ['_db_id' => null];
    }

    public static function taskSignalsVersion(): string
    {
        return self::activeTaskSignals()['version'];
    }

    /**
     * Идемпотентная установка исходных наборов при инициализации.
     */
    public static function seedDefaults(): void
    {
        foreach ([
            ['kind' => 'task_signals', 'file' => 'task_signals_v1.php'],
            ['kind' => 'stopwords', 'file' => 'stopwords_v1.php'],
        ] as $def) {
            $config = require __DIR__.'/'.$def['file'];
            $hash = hash('sha256', json_encode($config, JSON_UNESCAPED_UNICODE));

            $exists = DB::table('rule_sets')
                ->where('kind', $def['kind'])
                ->where('config_hash', $hash)
                ->exists();

            if (! $exists) {
                DB::table('rule_sets')->insert([
                    'id' => (string) \Illuminate\Support\Str::uuid(),
                    'kind' => $def['kind'],
                    'version' => $config['version'],
                    'config' => json_encode($config, JSON_UNESCAPED_UNICODE),
                    'config_hash' => $hash,
                    'is_active' => true,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            }
        }
    }
}
