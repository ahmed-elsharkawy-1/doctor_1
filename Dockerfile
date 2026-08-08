# syntax=docker/dockerfile:1

FROM php:8.3-fpm-bookworm AS php-base

WORKDIR /app

COPY --from=mlocati/php-extension-installer:2 /usr/bin/install-php-extensions /usr/local/bin/

RUN install-php-extensions \
    intl \
    opcache \
    pcntl \
    pdo_mysql \
    zip


FROM php-base AS vendor

COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

COPY composer.json composer.lock ./
RUN composer install \
    --no-dev \
    --prefer-dist \
    --no-interaction \
    --no-progress \
    --optimize-autoloader \
    --no-scripts

COPY . .
RUN composer dump-autoload --no-dev --optimize --classmap-authoritative


FROM node:22-alpine AS assets

WORKDIR /app

COPY package*.json vite.config.js ./
RUN if [ -f package-lock.json ]; then npm ci; else npm install; fi

COPY resources ./resources
COPY public ./public
COPY --from=vendor /app/vendor ./vendor

RUN npm run build


FROM php-base AS app

COPY --from=vendor /app /app
COPY --from=assets /app/public/build /app/public/build
COPY docker/production/entrypoint.sh /usr/local/bin/doctor-entrypoint
COPY docker/production/php.ini /usr/local/etc/php/conf.d/99-production.ini

RUN chmod +x /usr/local/bin/doctor-entrypoint \
    && mkdir -p \
        storage/app/private \
        storage/app/public \
        storage/framework/cache \
        storage/framework/sessions \
        storage/framework/views \
        storage/logs \
        bootstrap/cache \
    && ln -sf /app/storage/app/public public/storage \
    && chown -R www-data:www-data storage bootstrap/cache

EXPOSE 9000

ENTRYPOINT ["doctor-entrypoint"]
CMD ["php-fpm"]


FROM nginx:1.27-alpine AS web

WORKDIR /app

COPY --from=vendor /app/public /app/public
COPY --from=assets /app/public/build /app/public/build
COPY docker/production/nginx.conf /etc/nginx/conf.d/default.conf

RUN mkdir -p /app/storage/app/public \
    && ln -sf /app/storage/app/public /app/public/storage

EXPOSE 80
