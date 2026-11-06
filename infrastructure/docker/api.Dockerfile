FROM php:8.3.30-fpm-bookworm
RUN apt-get update && apt-get install -y --no-install-recommends libpq-dev libonig-dev libxml2-dev libsqlite3-dev unzip git libzip-dev && docker-php-ext-install pdo_pgsql pdo_sqlite mbstring pcntl dom xml xmlwriter zip && pecl install redis-6.3.0 && docker-php-ext-enable redis && rm -rf /var/lib/apt/lists/*
COPY --from=composer:2.9.2 /usr/bin/composer /usr/bin/composer
WORKDIR /var/www/html
COPY apps/api/composer.json apps/api/composer.lock ./
RUN composer install --no-interaction --no-scripts --prefer-dist
COPY apps/api/ ./
RUN touch .env && composer dump-autoload --optimize && chown -R www-data:www-data storage bootstrap/cache
USER www-data
EXPOSE 9000
CMD ["php-fpm"]
