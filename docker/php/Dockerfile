# syntax=docker/dockerfile:1
# Образ приложения: PHP-FPM + CLI (Artisan, очереди, MCP stdio).
# Версии зафиксированы (ТЗ §2): без latest.
FROM php:8.4.25-fpm-bookworm

RUN apt-get update && apt-get install -y --no-install-recommends \
        libpq-dev \
        libzip-dev \
        libicu-dev \
        unzip \
        git \
        procps \
    && rm -rf /var/lib/apt/lists/*

RUN docker-php-ext-install -j"$(nproc)" \
        pdo_pgsql \
        pgsql \
        intl \
        bcmath \
        zip \
        pcntl \
        opcache

COPY --from=composer:2.10.3 /usr/bin/composer /usr/bin/composer

# Настройки PHP: лимит загрузки 5 GiB согласован с Nginx и приложением (ТЗ §5.1).
COPY docker/php/php.ini /usr/local/etc/php/conf.d/zz-niche-radar.ini

WORKDIR /var/www/html

# Сначала зависимости (кеш слоя), затем исходники.
COPY backend/composer.json backend/composer.lock ./
RUN composer install --no-interaction --prefer-dist --no-scripts --no-autoloader

COPY backend/ ./
RUN composer dump-autoload --optimize \
    && chmod +x artisan \
    && php artisan --version

COPY docker/php/entrypoint.sh /usr/local/bin/niche-entrypoint
COPY docker/php/migrate.sh /usr/local/bin/niche-migrate
RUN chmod +x /usr/local/bin/niche-entrypoint /usr/local/bin/niche-migrate

ENTRYPOINT ["niche-entrypoint"]
CMD ["php-fpm"]
