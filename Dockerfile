# syntax=docker/dockerfile:1

FROM ghcr.io/akv85/voice-translator-php:8.4-grpc AS php-base

WORKDIR /var/www/html


FROM php-base AS composer-build

USER root

COPY composer.json composer.lock ./

RUN composer install \
    --no-dev \
    --no-interaction \
    --prefer-dist \
    --optimize-autoloader \
    --no-scripts

COPY . .

RUN composer dump-autoload \
    --no-dev \
    --optimize

USER www-data


FROM node:24-bookworm-slim AS frontend-build

WORKDIR /app

COPY package.json package-lock.json ./

RUN npm ci

COPY . .

RUN npm run build


FROM php-base AS runtime

WORKDIR /var/www/html

COPY --from=composer-build --chown=www-data:www-data \
    /var/www/html /var/www/html

COPY --from=frontend-build --chown=www-data:www-data \
    /app/public/build /var/www/html/public/build

USER root

RUN mkdir -p \
        storage/framework/cache/data \
        storage/framework/sessions \
        storage/framework/views \
        storage/logs \
        bootstrap/cache \
    && chown -R www-data:www-data \
        storage \
        bootstrap/cache

USER www-data

ENV APP_ENV=production
ENV APP_DEBUG=false
ENV PHP_OPCACHE_ENABLE=1

EXPOSE 8080

CMD ["sh", "-c", "php artisan serve --host=0.0.0.0 --port=${PORT:-8080}"]
