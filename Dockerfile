FROM node:22-bookworm-slim AS assets
WORKDIR /app
COPY package*.json ./
RUN npm ci
COPY resources ./resources
COPY vite.config.js ./
RUN npm run build
FROM php:8.4-fpm-bookworm
RUN apt-get update && apt-get install -y --no-install-recommends libpq-dev libonig-dev libxml2-dev libzip-dev unzip git && docker-php-ext-install pdo_pgsql mbstring xml dom zip pcntl && rm -rf /var/lib/apt/lists/*
COPY --from=composer:2 /usr/bin/composer /usr/bin/composer
WORKDIR /var/www/html
COPY . .
RUN composer install --no-dev --prefer-dist --no-interaction --optimize-autoloader
COPY --from=assets /app/public/build ./public/build
COPY deploy/php.ini /usr/local/etc/php/conf.d/uploads.ini
RUN chown -R www-data:www-data storage bootstrap/cache
USER www-data
CMD ["php-fpm"]
