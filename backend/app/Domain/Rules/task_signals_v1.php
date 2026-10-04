<?php

/**
 * Стартовый набор правил признаков «задача для онлайн-сервиса» (ТЗ §7.3).
 * Веса 0…1; итоговый T — максимум веса сработавшего правила.
 * Словарь редактируется владельцем в БД (rule_sets), эта версия —
 * исходная, проверяется на демонстрационных примерах.
 */
return [
    'version' => 'task_signals_v1',
    'categories' => [
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
    'rules' => [
        // --- Конвертация (сильные сигналы) ---
        ['id' => 'conv_tool', 'category' => 'conversion', 'weight' => 1.0,
         'description' => 'Слово «конвертер» и явные формы глагола',
         'any_tokens' => ['конвертер', 'конвертеры', 'конвертера', 'конвертеров', 'конвертировать', 'конвертация', 'конвертирует']],
        ['id' => 'conv_pair', 'category' => 'conversion', 'weight' => 0.9,
         'description' => 'Комбинации вида «из pdf в …», «… в pdf»',
         'phrase_contains' => ['из pdf в', 'из jpg в', 'из png в', 'pdf в word', 'word в pdf', 'pdf в jpg', 'jpg в pdf', 'pdf в excel', 'excel в pdf', 'pdf в png', 'в pdf онлайн', 'в pdf бесплатно']],

        // --- Расчёт ---
        ['id' => 'calc_tool', 'category' => 'calculation', 'weight' => 0.9,
         'description' => 'Калькулятор или явное «рассчитать»',
         'any_tokens' => ['калькулятор', 'рассчитать', 'рассчитай', 'посчитать', 'расчет', 'расчёт', 'расчеты', 'расчёты']],
        ['id' => 'calc_weak', 'category' => 'calculation', 'weight' => 0.5,
         'description' => '«расчет» в составе составных слов',
         'phrase_contains' => ['калькулятор']],

        // --- Генерация ---
        ['id' => 'gen_tool', 'category' => 'generation', 'weight' => 0.9,
         'description' => 'Генератор или явное «сгенерировать»',
         'any_tokens' => ['генератор', 'генераторы', 'сгенерировать', 'генерация', 'создать', 'сделать']],
        ['id' => 'gen_strong', 'category' => 'generation', 'weight' => 0.9,
         'description' => 'Комбинации «генератор + объект»',
         'phrase_contains' => ['генератор парол', 'генератор qr', 'сгенерировать парол']],

        // --- Обработка файлов ---
        ['id' => 'file_pdf', 'category' => 'file_processing', 'weight' => 0.8,
         'description' => 'Операции над PDF-файлами',
         'phrase_contains' => ['сжать pdf', 'объединить pdf', 'склеить pdf', 'разделить pdf', 'редактор pdf', 'подписать pdf', 'повернуть pdf', 'удалить страницу pdf']],
        ['id' => 'file_edit', 'category' => 'file_processing', 'weight' => 0.7,
         'description' => 'Редакторы и конвертация изображений',
         'any_tokens' => ['фоторедактор', 'обрезать', 'сжать', 'ресайз']],
        ['id' => 'file_compress', 'category' => 'file_processing', 'weight' => 0.6,
         'description' => 'Сжатие/архивация',
         'any_tokens' => ['сжатие', 'конвертация']],

        // --- Мониторинг ---
        ['id' => 'mon_tool', 'category' => 'monitoring', 'weight' => 0.8,
         'description' => 'Мониторинг и отслеживание',
         'any_tokens' => ['мониторинг', 'отслеживать', 'отследить', 'следить']],

        // --- Сравнение ---
        ['id' => 'cmp_tool', 'category' => 'comparison', 'weight' => 0.7,
         'description' => 'Сравнение вариантов',
         'any_tokens' => ['сравнить', 'сравнение', 'сравнения']],

        // --- Учёт ---
        ['id' => 'acc_tool', 'category' => 'accounting', 'weight' => 0.7,
         'description' => 'Учёт и таблицы',
         'any_tokens' => ['учет', 'учёт', 'бухгалтерия', 'табель', 'ведомость']],

        // --- Автоматизация ---
        ['id' => 'auto_tool', 'category' => 'automation', 'weight' => 0.8,
         'description' => 'Автоматизация действий',
         'any_tokens' => ['автоматизировать', 'автоматизация', 'автоматически']],

        // --- Извлечение данных ---
        ['id' => 'parse_tool', 'category' => 'parsing', 'weight' => 0.7,
         'description' => 'Скачивание и парсинг из источников',
         'phrase_contains' => ['скачать с ютуба', 'скачать из инстаграм', 'скачать видео с', 'скачать музыку с', 'скачать все фото']],
        ['id' => 'parse_weak', 'category' => 'parsing', 'weight' => 0.3,
         'description' => 'Одиночное «скачать» — слабый сигнал',
         'any_tokens' => ['скачать']],

        // --- Слабые сигналы ---
        ['id' => 'weak_online', 'category' => 'weak', 'weight' => 0.2,
         'description' => 'Одиночное «онлайн» встречается и в развлекательных запросах',
         'any_tokens' => ['онлайн']],
        ['id' => 'weak_check', 'category' => 'weak', 'weight' => 0.3,
         'description' => 'Одиночное «проверить» без объекта',
         'any_tokens' => ['проверить', 'проверка']],
    ],
];
