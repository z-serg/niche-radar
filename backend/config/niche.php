<?php

/*
|--------------------------------------------------------------------------
| Настройки предметной области Niche Radar
|--------------------------------------------------------------------------
| Пороги пресетов и профиля приоритета — стартовые значения из ТЗ §7.2;
| изменяются через профиль metric_run (создаётся новая версия), а не
| правкой этого файла в работающей системе.
*/

return [

    // Лимит загрузки одного CSV (байты). Согласован с Nginx и PHP.
    'upload_max_bytes' => (int) env('UPLOAD_MAX_BYTES', 5 * 1024 * 1024 * 1024),

    // Число строк предпросмотра при загрузке отчёта.
    'preview_rows' => 100,

    // Пакет строк импортёра: ограничивает память и точки проверки отмены.
    'import_batch_rows' => 20000,

    // Пресеты поиска (ТЗ §7.2). Поля: thresholds + sort.
    'presets' => [
        'sustained_growth' => [
            'title' => 'Устойчивый рост',
            'min_observations' => 4,
            'exact_current_min' => 100,
            'base_min' => 20,
            'smoothed_delta_min' => 50,
            'smoothed_growth_min' => 0.30,
            'consistency_min' => 0.60,
            'peak_retention_min' => 0.70,
            'sort' => [['field' => 'priority', 'direction' => 'desc']],
        ],
        'early_signals' => [
            'title' => 'Ранние сигналы',
            'min_observations' => 4,
            'base_min' => 0.0001, // строгое 0 < B < 20: минимум отсекает B=0
            'base_max_exclusive' => 20,
            'exact_current_min' => 50,
            'smoothed_delta_min' => 30,
            'smoothed_growth_min' => 1.0,
            'consistency_min' => 0.60,
            'peak_retention_min' => 0.70,
            'sort' => [['field' => 'smoothed_delta', 'direction' => 'desc']],
        ],
        'zero_baseline' => [
            'title' => 'Рост от нуля',
            'min_observations' => 4,
            'base_zero' => true,
            'exact_current_min' => 50,
            'smoothed_delta_min' => 30,
            'peak_retention_min' => 0.70,
            'sort' => [['field' => 'smoothed_delta', 'direction' => 'desc']],
        ],
        // Ранжирование по сглаженному приросту A без порогов — те же условия,
        // что у карточек «Лидеры роста» / «Лидеры падения» на дашборде (§5.7):
        // полный ряд (A определён) и сортировка по A.
        'growth_leaders' => [
            'title' => 'Лидеры роста',
            'min_observations' => 4,
            'smoothed_not_null' => true,
            'sort' => [['field' => 'smoothed_delta', 'direction' => 'desc']],
        ],
        'fall_leaders' => [
            'title' => 'Лидеры падения',
            'min_observations' => 4,
            'smoothed_not_null' => true,
            'sort' => [['field' => 'smoothed_delta', 'direction' => 'asc']],
        ],
    ],

    // Разрешённые категории признаков задачи (§7.3), общий словарь UI/API/MCP.
    'task_categories' => [
        'conversion' => 'Конвертация форматов',
        'calculation' => 'Расчёт',
        'generation' => 'Генерация',
        'file_processing' => 'Обработка файлов',
        'monitoring' => 'Мониторинг',
        'comparison' => 'Сравнение',
        'accounting' => 'Учёт',
        'automation' => 'Автоматизация',
        'parsing' => 'Извлечение данных',
        'weak' => 'Слабый сигнал (не трактовать как задачу)',
    ],

    // Фиксированные масштабы нормализации priority_v1 (ТЗ §7.4).
    'priority' => [
        'version' => 'priority_v1',
        'g_scale' => 2.0,
        'a_scale' => 10000.0,
        'v_scale' => 100000.0,
        'weights' => ['g' => 0.30, 'a' => 0.25, 'c' => 0.20, 'v' => 0.15, 't' => 0.10],
        'min_base_for_priority' => 20.0,
    ],

    // Порог «низкой базы» для метки low_base.
    'low_base_threshold' => 20,

    // Автопредложения групп (ТЗ §7.6).
    'groups' => [
        'top_set_limit' => 100000,
        'min_group_size' => 5,
    ],

    // Поиск: страница по умолчанию и максимум.
    'search_page_default' => 50,
    'search_page_max' => 200,
    // Жёсткий потолок глубины курсорной выдачи для одного запроса.
    'search_hard_limit' => 100000,
    // Лимит объёма ответа MCP (ТЗ §10).
    'mcp_response_limit_bytes' => 256 * 1024,

    // Время жизни временных экспортов, дни.
    'export_retention_days' => 7,

    // Зависшие задания: heartbeat старше N минут при активном статусе.
    'stale_job_minutes' => 15,

];
