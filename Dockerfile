FROM composer:2 AS composer

FROM php:8.4-cli-bookworm
RUN apt-get update \
    && apt-get install -y --no-install-recommends libpq-dev unzip \
    && docker-php-ext-install pdo_pgsql pcntl \
    && rm -rf /var/lib/apt/lists/*
RUN printf "upload_max_filesize=16M\npost_max_size=18M\n" > /usr/local/etc/php/conf.d/mailbox.ini
COPY --from=composer /usr/bin/composer /usr/local/bin/composer
WORKDIR /app
COPY . .
RUN touch .env \
    && composer install --no-interaction --prefer-dist --optimize-autoloader \
    && mkdir -p /var/lib/rank-it/staging /var/www/.config \
    && chown -R www-data:www-data /app/storage /app/bootstrap/cache /var/lib/rank-it/staging /var/www/.config \
    && chmod 700 /var/lib/rank-it/staging
USER www-data
