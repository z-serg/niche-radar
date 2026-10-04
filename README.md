# Niche Radar

Персональный инструмент исследования поискового спроса и поиска ниш для онлайн-сервисов.
Превращает CSV-отчёты популярных запросов Яндекса (Wordstat/Bukvarix) в проверяемые
продуктовые гипотезы.

- Версия: 1.0 · Формат: локальное веб-приложение без аутентификации (Docker Compose)
- Техническое задание: [niche-radar-spec.md](niche-radar-spec.md)

## Что внутри

| Слой | Технологии |
|---|---|
| Backend | PHP 8.4 + Laravel 13, модульный монолит (`backend/app/Domain`) |
| Frontend | React 18 + TypeScript + Vite, Tailwind CSS, TanStack Query, Recharts |
| БД | PostgreSQL 17 + `pg_trgm` (индексированный LIKE и похожие строки) |
| Фоновые задания | Laravel Queue (database-драйвер): очередь `import` (concurrency=1) и `default` |
| MCP | `laravel/mcp`, локальный транспорт stdio, только чтение |
| HTTP | Nginx: статика React + FastCGE в php-fpm; порт `127.0.0.1:8080` (настраивается) |

## Быстрый старт

Требуется только Docker (Docker Desktop или Engine + Compose v2). PHP, Node.js и
PostgreSQL на хосте не нужны.

```bash
./bin/setup                 # создаёт .env: APP_KEY, пароль БД
make up                     # сборка, запуск и открытие сайта в браузере
```

Управление стеком: `make down` (остановить), `make restart`, `make logs`, `make ps`,
`make help` — полный список.

После старта приложение доступно на `http://localhost:8080`
(если порт занят — поменяйте `APP_PORT` в `.env`, см. ниже).
Аутентификации нет: приложение локальное, вход не требуется.

### Импорт данных

Через UI («Данные» → загрузка CSV) или из терминала:

```bash
./bin/import /absolute/path/Top3000000.csv --coverage=full_top
```

Контрольные значения образца `Top10.csv` (ТЗ §4.3) проверяются автотестами:
`./bin/test`.

### Другие команды

| Команда | Назначение |
|---|---|
| `./bin/setup` | Инициализация `.env` (не перезаписывает существующие значения) |
| `./bin/import <путь>` | CLI-импорт CSV без лимитов HTTP |
| `./bin/backup <каталог>` | Согласованная копия БД + оригиналы + метаданные |
| `./bin/restore <каталог>` | Восстановление с подтверждением замены |
| `./bin/test` | Тесты в Docker (отдельная БД `niche_radar_test`) |
| `./bin/benchmark` | Контрольная нагрузка и отчёт p50/p95 (`benchmarks/`) |

## MCP для внешнего ИИ-агента

Read-only сервер `niche-radar` (12 инструментов + ресурс `niche-radar://methodology`).
Конфигурация клиента: [docs/mcp-client.example.json](docs/mcp-client.example.json).

```bash
docker compose exec -T app php artisan mcp:start niche-radar
```

Транспорт stdio рассчитан на локального агента на Docker-хосте (доверие владельца).
HTTP/OAuth и публичный адрес не входят в версию 1.0 — задокументированное ограничение (ТЗ §10).

## REST API

Префикс `/api/v1`, JSON, единая схема фильтров для UI и MCP. Описание:
[docs/openapi.yaml](docs/openapi.yaml). Аутентификации нет — REST доступен без
токенов (локальный loopback-доступ).

## Документация

- [docs/openapi.yaml](docs/openapi.yaml) — REST API (OpenAPI 3)
- [docs/data-model.md](docs/data-model.md) — схема данных, формулы метрик, обработка пропусков
- [docs/runbook.md](docs/runbook.md) — эксплуатация: обновление, бэкапы, восстановление, секреты
- [docs/mcp-client.example.json](docs/mcp-client.example.json) — конфигурация MCP-клиента
- [AGENTS.md](AGENTS.md) — инструкция для ИИ-агентов, работающих с репозиторием

## Структура репозитория

```
backend/    Laravel: API, импорт, метрики, MCP, тесты
frontend/   React SPA (Vite, Tailwind)
docker/     Dockerfile-ы (php-fpm, nginx), конфиги, init.sql
bin/        setup / import / backup / restore / test / benchmark
docs/       OpenAPI, схема данных, runbook, MCP
data/       Пользовательские CSV — в git НЕ включаются
```

## Ограничения источника (важно для интерпретации)

- Даты замеров неизвестны: ось истории порядковая, месячные/годовые темпы не считаются.
- «Впервые в рейтинге» ≠ «впервые возникший спрос».
- Отсутствие строки, пустая ячейка (NULL) и ноль — три разных состояния.
- Широкие частотности не суммируются как объём рынка; любая сумма точных подписывается
  «суммарная точная частотность выбранных формулировок».
- «Приоритет исследования» — эвристический рейтинг для первичного отбора, не вероятность
  успеха и не оценка прибыльности; данных о конкуренции в данных нет.
