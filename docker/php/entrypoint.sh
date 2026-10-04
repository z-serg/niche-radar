#!/bin/sh
# Идемпотентная подготовка окружения контейнера приложения:
# skeleton storage на volume + права для www-data (php-fpm).
set -e

mkdir -p \
    storage/framework/cache/data \
    storage/framework/sessions \
    storage/framework/views \
    storage/framework/testing \
    storage/logs \
    storage/app/originals \
    storage/app/staging \
    storage/app/exports \
    storage/app/benchmarks \
    bootstrap/cache

chown -R www-data:www-data storage bootstrap/cache 2>/dev/null || true
chmod -R ug+rwX storage bootstrap/cache 2>/dev/null || true

exec "$@"
