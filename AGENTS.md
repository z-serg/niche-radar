# AGENTS.md — Niche Radar

Инструкция для ИИ-агентов (и людей), работающих с этим репозиторием.

## Общие правила

**ВАЖНО!!! Ничего сам не выдумывай** — если существуют неоднозначности или развилка решений, задавай вопросы пользователю!

## Проект

Niche Radar — персональный инструмент исследования поискового спроса по CSV-отчётам
Яндекс Wordstat (Bukvarix). Полное техническое задание: `niche-radar-spec.md` —
это источник истины по требованиям; при конфликте кода и ТЗ приоритет у ТЗ.

## Команды

Всё выполняется в Docker; PHP/Node/PostgreSQL на хосте не требуются.

```bash
./bin/setup                                   # один раз: .env + секреты
make up                                       # поднять стек + открыть сайт в браузере
make down                                     # остановить стек (тома сохраняются)
./bin/import /abs/path/report.csv             # импорт CSV
./bin/test                                    # тесты (БД niche_radar_test, изолирована)
./bin/backup /abs/path/dir                    # бэкап
docker compose exec -T app php artisan ...    # artisan
docker compose logs -f worker-import          # логи очереди импорта
```

- Приложение: `http://localhost:8080` (порт — `APP_PORT` в `.env`; порт 8080 на машине
  разработчика занят, локально используется 8180 — значение в git не фиксируется).
- Отладка фронтенда: `cd frontend && npm run dev` (Vite на :5173, proxy `/api` → web),
  либо пересборка образа `docker compose build web`.

## Работа с Git

- Каждая фича реализуется в новой git-ветке, ответвлённой от `master`.
- Коммиты разрешены только с явного разрешения пользователя.
- Пуш в удаленный репозиторий **ЗАПРЕЩЕН!**

## Архитектура

- `backend/app/Domain/` — модульный монолит:
  - `Datasets/` — приём файлов (SHA-256 дедупликация), `BukvarixCsvAdapter` (распознавание
    заголовков по именам, не позициям), `ImportProcessor` (потоковая валидация → COPY в
    staging-таблицы → set-based загрузка), `DatasetManager` (жизненный цикл отчёта);
  - `Metrics/` — `MetricFormulas` (канонические формулы metrics_v1; юнит-тесты — эталон),
    `MetricRunService` (версионированные прогоны; bulk-SQL зеркалирует формулы, сверка —
    тестом ImportTop10Test на контрольных значениях §14.1);
  - `Search/` — `FilterPayload` (единая схема фильтров UI/REST/MCP), `KeywordSearchService`
    (keyset-пагинация, вторичный ключ keyword_id, NULLS LAST), `Presets`;
  - `Niches/`, `Analytics/` (summary/compare/dashboard), `Groups/` (автопредложения),
    `Rules/` (признаки задачи + стоп-слова, версионируются в БД), `Exports/`.
- `backend/app/Mcp/` — read-only сервер `niche-radar` (stdio) для внешних агентов.
- `backend/app/Jobs/` — очереди: `import` (concurrency=1, timeout<retry_after) и `default`.
- `frontend/src/` — страницы: Data, Explore (URL-состояние фильтров, курсорная пагинация),
  Keyword card, Candidates (2 вкладки), Niches (версии состава), Hypotheses, Dashboard, Settings.
- Импорт идемпотентен: этапы чистят свои данные; повторный запуск не дублирует наблюдения.
- Одинаковый файл (SHA-256) не дублируется: возвращается существующий отчёт.

## Критичные правила (нарушение ломало данные)

1. **Никогда не запускайте тесты против рабочей БД.** Только `./bin/test` — он создаёт
   `niche_radar_test` и принудительно перекрывает `DB_*`. `tests/TestCase.php` падает,
   если связь указывает на рабочую базу.
2. **CSV нельзя разбивать split'ом по переводам строк** — используйте `App\Support\CsvStreamer`
   (кавычки, CRLF, BOM, переносы в полях).
3. **NULL ≠ 0 ≠ отсутствие строки** — это семантика ТЗ; не подменяйте при правках SQL/PHP.
4. **Формулы метрик** меняются только с новой версией (`MetricFormulas::VERSION`) и
   обновлением тестов §14.1. Не правьте пороги пресетов в коде без нового профиля metric_run.
5. Приложение локальное: аутентификации нет, REST (включая мутации) доступен без
   входа и токенов; MCP — всегда read-only. Не возвращайте auth-слой без правки ТЗ.
6. `data/`, `.env`, `backups/`, `benchmarks/` — не коммитятся.

## Конвенции

- PHP 8.4, strict-типизация в новых доменных классах, Laravel 13 (без api.php-контроллеров-
  заглушек; тонкие контроллеры над `app/Domain`).
- Именования БД — snake_case; таблицы по ТЗ §8 (`source_files`, `datasets`, `dataset_scans`,
  `keywords`, `dataset_keywords`, `observations`, `metric_runs`, `keyword_metrics`, `niche_versions`…).
- UI — русский, desktop-first (≥1280px); рост/падение различаются не только цветом
  (стрелка + подпись); все фильтры доступны с клавиатуры.
- Комментарии в коде — по-русски, ссылаются на пункты ТЗ (§N).
- Коммиты: `тип: суть` (`import:`, `search:`, `ui:`, `docs:`, `infra:`), без мусора.

## Проверка перед «готово»

1. `./bin/test` зелёный.
2. Импорт `data/Top10.csv`: 10 фраз / 10 dataset_keywords / 6 замеров / 60 observations;
   переводчик exact_current=21466358, история 24704716,24993362,25094186,24831529,25726636,21466358;
   пресет устойчивого роста — 0 из 10.
3. `docker compose ps` — все сервисы healthy/running; `curl localhost:8080/api/v1/health` — healthy.
