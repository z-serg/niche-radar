<?php

use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Middleware;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        apiPrefix: 'api',
        commands: __DIR__.'/../routes/console.php',
        // routes/ai.php подключает сам McpServiceProvider (laravel/mcp).
    )
    ->withCommands([
        __DIR__.'/../app/Console/Commands',
    ])
    ->withMiddleware(function (Middleware $middleware) {
        // Локальное приложение без аутентификации: сессии и CSRF для API не нужны.
        $middleware->throttleApi('120,1');
    })
    // Регистрирует стандартный обработчик исключений (app/Exceptions нет).
    ->withExceptions()
    ->create();
