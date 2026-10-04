<?php

return [

    'default' => env('QUEUE_DEFAULT_CONNECTION', 'default'),

    'connections' => [

        // Драйвер database на PostgreSQL (ТЗ §2). Два соединения нужны,
        // потому что retry_after задаётся на соединение: у тяжёлого
        // импорта и у интерактивных заданий разные таймауты (ТЗ §6.2).
        'import' => [
            'driver' => 'database',
            'connection' => env('DB_CONNECTION', 'pgsql'),
            'table' => 'jobs',
            'queue' => 'import',
            'retry_after' => (int) env('QUEUE_IMPORT_RETRY_AFTER', 14400),
            'after_commit' => true,
        ],

        'default' => [
            'driver' => 'database',
            'connection' => env('DB_CONNECTION', 'pgsql'),
            'table' => 'jobs',
            'queue' => 'default',
            'retry_after' => (int) env('QUEUE_DEFAULT_RETRY_AFTER', 900),
            'after_commit' => true,
        ],

        'sync' => [
            'driver' => 'sync',
        ],

    ],

    'batching' => [
        'database' => env('DB_CONNECTION', 'pgsql'),
        'table' => 'job_batches',
    ],

    'failed' => [
        'driver' => 'database-uuids',
        'database' => env('DB_CONNECTION', 'pgsql'),
        'table' => 'failed_jobs',
    ],

];
