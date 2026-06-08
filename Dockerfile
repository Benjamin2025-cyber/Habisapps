FROM php:8.4-cli-bookworm

ENV COMPOSER_ALLOW_SUPERUSER=1 \
    APP_ENV=production \
    APP_DEBUG=false

WORKDIR /var/www/html

# Use the PGDG apt repo so the bundled client (pg_dump/pg_restore/psql) matches
# the production server major version. Debian bookworm ships client 15, which
# refuses to dump a >= 16 server; the database-management backup runner needs a
# client >= the live server (currently 16).
RUN apt-get update \
    && apt-get install -y --no-install-recommends \
        ca-certificates \
        curl \
        git \
        gnupg \
        libpq-dev \
        libzip-dev \
        unzip \
        zip \
    && install -d /usr/share/postgresql-common/pgdg \
    && curl -fsSL https://www.postgresql.org/media/keys/ACCC4CF8.asc \
        -o /usr/share/postgresql-common/pgdg/apt.postgresql.org.asc \
    && echo "deb [signed-by=/usr/share/postgresql-common/pgdg/apt.postgresql.org.asc] http://apt.postgresql.org/pub/repos/apt bookworm-pgdg main" \
        > /etc/apt/sources.list.d/pgdg.list \
    && apt-get update \
    && apt-get install -y --no-install-recommends postgresql-client-16 \
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
