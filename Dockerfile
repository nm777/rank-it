FROM composer:2 AS composer

FROM php:8.4-cli-bookworm
RUN apt-get update \
    && apt-get install -y --no-install-recommends libjpeg62-turbo-dev libpng-dev libwebp-dev libpq-dev libzip-dev poppler-utils unzip \
    && docker-php-ext-configure gd --with-jpeg --with-webp \
    && docker-php-ext-install exif gd pdo_pgsql pcntl zip \
    && rm -rf /var/lib/apt/lists/*
RUN printf "upload_max_filesize=16M\npost_max_size=80M\n" > /usr/local/etc/php/conf.d/mailbox.ini
COPY --from=composer /usr/bin/composer /usr/local/bin/composer
WORKDIR /app
COPY . .
RUN touch .env \
    && composer install --no-interaction --prefer-dist --optimize-autoloader \
    && php -r 'require "vendor/autoload.php"; echo App\AppVersion::sourceHash(getcwd()), PHP_EOL;' > bootstrap/release-id \
    && mkdir -p /var/lib/rank-it/staging /var/www/.config \
    && chown -R www-data:www-data /app/storage /app/bootstrap/cache /var/lib/rank-it/staging /var/www/.config \
    && chmod 700 /var/lib/rank-it/staging
USER www-data
