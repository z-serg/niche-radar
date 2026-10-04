<?php

use App\Mcp\Servers\NicheRadarServer;
use Laravel\Mcp\Facades\Mcp;

// Локальный MCP-сервер для внешнего ИИ-агента, транспорт stdio (ТЗ §10).
// Запуск: docker compose exec -T app php artisan mcp:start niche-radar
Mcp::local('niche-radar', NicheRadarServer::class);
