<?php

use Monolog\Handler\NullHandler;
use Monolog\Handler\StreamHandler;

return [

    'default' => env('LOG_CHANNEL', 'stderr'),

    'channels' => [

        'stderr' => [
            'driver' => 'monolog',
            'level' => env('LOG_LEVEL', 'info'),
            'handler' => StreamHandler::class,
            'handler_with' => [
                'stream' => 'php://stderr',
            ],
        ],

        'stack' => [
            'driver' => 'stack',
            'channels' => explode(',', env('LOG_STACK', 'stderr')),
            'ignore_exceptions' => false,
        ],

        'stdout' => [
            'driver' => 'monolog',
            'level' => env('LOG_LEVEL', 'info'),
            'handler' => StreamHandler::class,
            'handler_with' => [
                'stream' => 'php://stdout',
            ],
        ],

        'daily' => [
            'driver' => 'daily',
            'path' => storage_path('logs/laravel.log'),
            'level' => env('LOG_LEVEL', 'info'),
            'days' => env('LOG_DAILY_DAYS', 7),
            'replace_placeholders' => true,
        ],

        'stderr_import' => [
            'driver' => 'monolog',
            'level' => env('LOG_LEVEL', 'info'),
            'handler' => StreamHandler::class,
            'handler_with' => [
                'stream' => 'php://stderr',
            ],
            'processor' => null,
        ],

        'null' => [
            'driver' => 'monolog',
            'handler' => NullHandler::class,
        ],

    ],

];
