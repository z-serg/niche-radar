#!/bin/sh
# Однократные миграции и идемпотентное заполнение стартовых правил до старта
# приложения (ТЗ §12.1, §12.2). Выполняется сервисом migrate.
set -e

echo "[migrate] ожидание готовности PostgreSQL..."
until php -r '
    try {
        $pdo = new PDO(
            sprintf("pgsql:host=%s;port=%s;dbname=%s", getenv("DB_HOST"), getenv("DB_PORT"), getenv("DB_DATABASE")),
            getenv("DB_USERNAME"),
            getenv("DB_PASSWORD"),
            [PDO::ATTR_TIMEOUT => 5]
        );
        exit(0);
    } catch (Throwable $e) { exit(1); }
'; do
    sleep 2
done

echo "[migrate] миграции..."
php artisan migrate --force

echo "[migrate] заполнение стартовых правил..."
php artisan niche:seed-rules

echo "[migrate] готово"
