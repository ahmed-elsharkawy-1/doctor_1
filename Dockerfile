# syntax=docker/dockerfile:1

FROM dunglas/frankenphp:1-php8.3 AS vendor

WORKDIR /app

RUN install-php-extensions \
    intl \
    pdo_mysql \
    zip

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


FROM dunglas/frankenphp:1-php8.3 AS runtime

WORKDIR /app

RUN install-php-extensions \
    intl \
    opcache \
    pcntl \
    pdo_mysql \
    zip

COPY --from=vendor /app /app
COPY --from=assets /app/public/build /app/public/build
COPY docker/production/Caddyfile /etc/caddy/Caddyfile
COPY docker/production/entrypoint.sh /usr/local/bin/doctor-entrypoint

RUN chmod +x /usr/local/bin/doctor-entrypoint \
    && mkdir -p \
        storage/app/private \
        storage/app/public \
        storage/framework/cache \
        storage/framework/sessions \
        storage/framework/views \
        storage/logs \
        bootstrap/cache \
    && chown -R www-data:www-data storage bootstrap/cache

EXPOSE 8000

ENTRYPOINT ["doctor-entrypoint"]
