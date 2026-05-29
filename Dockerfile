FROM php:8.4-cli-bookworm

ENV COMPOSER_ALLOW_SUPERUSER=1 \
    APP_ENV=production \
    APP_DEBUG=false

WORKDIR /var/www/html

RUN apt-get update \
    && apt-get install -y --no-install-recommends \
        git \
        libpq-dev \
        postgresql-client \
        libzip-dev \
        unzip \
        zip \
    && docker-php-ext-install pdo_pgsql pgsql zip exif \
    && rm -rf /var/lib/apt/lists/*

COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

COPY composer.json composer.lock ./
RUN composer install --no-interaction --no-dev --prefer-dist --no-scripts --no-progress

COPY . .

RUN composer dump-autoload --optimize \
    && php artisan package:discover --ansi

EXPOSE 8000

HEALTHCHECK --interval=5s --timeout=5s --start-period=30s --retries=5 \
    CMD php -r "exit(fsockopen('localhost', 8000) ? 0 : 1);" 2>/dev/null

CMD ["php", "artisan", "serve", "--host=0.0.0.0", "--port=8000"]
