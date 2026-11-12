# syntax=docker/dockerfile:1.7
#
# Stash production image (multi-stage).
#   target "app"  : PHP-FPM application (also used by worker + scheduler)
#   target "web"  : Nginx serving /public and proxying PHP to "app"
# Node is used only in the "assets" build stage, never at runtime.

ARG PHP_VERSION=8.3
ARG NODE_VERSION=24

# ---------------------------------------------------------------------------
FROM php:${PHP_VERSION}-fpm-bookworm AS php-base

RUN set -eux; \
    apt-get update; \
    apt-get install -y --no-install-recommends \
        libpq-dev libicu-dev libzip-dev libjpeg62-turbo-dev libpng-dev libwebp-dev libfreetype6-dev \
        unzip ca-certificates; \
    docker-php-ext-configure gd --with-jpeg --with-webp --with-freetype; \
    docker-php-ext-install -j"$(nproc)" pdo_pgsql pgsql intl zip bcmath gd exif opcache pcntl; \
    apt-get purge -y --auto-remove libpq-dev libicu-dev libzip-dev libjpeg62-turbo-dev libpng-dev libwebp-dev libfreetype6-dev; \
    apt-get install -y --no-install-recommends libpq5 libicu72 libzip4 libjpeg62-turbo libpng16-16 libwebp7 libwebpmux3 libfreetype6; \
    rm -rf /var/lib/apt/lists/*

COPY docker/php/php.ini /usr/local/etc/php/conf.d/zz-stash.ini
COPY docker/php/www.conf /usr/local/etc/php-fpm.d/zz-stash.conf
COPY --from=composer:2.8 /usr/bin/composer /usr/local/bin/composer

WORKDIR /var/www/html

# ---------------------------------------------------------------------------
FROM php-base AS vendor
COPY composer.json composer.lock ./
RUN composer install --no-dev --no-interaction --no-progress --no-scripts --prefer-dist --no-autoloader

# ---------------------------------------------------------------------------
# Assets need PHP too: the Wayfinder Vite plugin runs `php artisan wayfinder:generate`.
FROM node:${NODE_VERSION}-bookworm-slim AS node
FROM php-base AS assets
COPY --from=node /usr/local/bin/node /usr/local/bin/node
COPY --from=node /usr/local/lib/node_modules /usr/local/lib/node_modules
RUN ln -s /usr/local/lib/node_modules/npm/bin/npm-cli.js /usr/local/bin/npm \
 && ln -s /usr/local/lib/node_modules/npm/bin/npx-cli.js /usr/local/bin/npx
COPY package.json package-lock.json ./
RUN npm ci --no-audit --no-fund
COPY --from=vendor /var/www/html/vendor ./vendor
COPY . .
RUN composer dump-autoload --no-dev --optimize \
 && cp .env.example .env && php artisan key:generate --force \
 && npm run build \
 && rm -f .env

# ---------------------------------------------------------------------------
FROM php-base AS app
ENV APP_ENV=production \
    LOG_CHANNEL=stderr
COPY --chown=www-data:www-data . .
COPY --from=vendor --chown=www-data:www-data /var/www/html/vendor ./vendor
COPY --from=assets --chown=www-data:www-data /var/www/html/public/build ./public/build
RUN composer dump-autoload --no-dev --optimize --classmap-authoritative \
 && rm -rf node_modules tests/e2e storage/logs/*.log bootstrap/cache/*.php public/hot \
 && mkdir -p storage/app/private/stash storage/framework/cache/data storage/framework/sessions storage/framework/views storage/logs bootstrap/cache \
 && chown -R www-data:www-data storage bootstrap/cache \
 && chmod -R u=rwX,g=rX,o= storage bootstrap/cache
USER www-data
EXPOSE 9000
CMD ["php-fpm", "-F"]

# ---------------------------------------------------------------------------
FROM nginx:1.28-alpine AS web
COPY docker/nginx/default.conf /etc/nginx/conf.d/default.conf
COPY --from=app /var/www/html/public /var/www/html/public
HEALTHCHECK --interval=30s --timeout=5s --retries=3 CMD wget -qO- http://127.0.0.1/up >/dev/null || exit 1
