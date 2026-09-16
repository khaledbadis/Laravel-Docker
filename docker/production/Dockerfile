# syntax=docker/dockerfile:1
FROM php:8.4-fpm-bookworm@sha256:075b11566518bfa979bb9f2fe2e5359148326d659b15a2f414c2c305a0479a4e AS extensions
RUN apt-get update && apt-get install -y --no-install-recommends libpq-dev libicu-dev libzip-dev \
    && docker-php-ext-install -j"$(nproc)" pdo_pgsql intl zip opcache

FROM php:8.4-fpm-bookworm@sha256:075b11566518bfa979bb9f2fe2e5359148326d659b15a2f414c2c305a0479a4e AS runtime
RUN apt-get update && apt-get install -y --no-install-recommends libpq5 libicu72 libzip4 \
    && rm -rf /var/lib/apt/lists/*
COPY --from=extensions /usr/local/lib/php/extensions/ /usr/local/lib/php/extensions/
COPY --from=extensions /usr/local/etc/php/conf.d/ /usr/local/etc/php/conf.d/
COPY docker/production/php.ini /usr/local/etc/php/conf.d/production.ini
WORKDIR /var/www/html

FROM composer:2.10.3@sha256:d8f6343d3fae98107426bc49163ccad46ef85aabd4a27d80a74401fab4aba332 AS composer
FROM runtime AS dependencies
COPY --from=composer /usr/bin/composer /usr/local/bin/composer
COPY composer.json composer.lock ./
RUN composer install --no-dev --no-scripts --no-autoloader --prefer-dist --no-interaction
COPY . .
RUN composer dump-autoload --no-dev --classmap-authoritative --no-scripts \
    && rm -f bootstrap/cache/*.php \
    && mkdir -p storage/app/private storage/app/public storage/framework/cache/data storage/framework/sessions storage/framework/views

FROM node:24-bookworm-slim@sha256:2fe369e969550cde8e867afc3fe370b260140cab4a23d467074295b42163d553 AS assets
WORKDIR /build
COPY package.json package-lock.json .npmrc ./
RUN npm ci
COPY --from=dependencies /var/www/html /build
RUN npm run build

FROM runtime AS app
ARG RELEASE_SHA
ARG SOURCE_URL
LABEL org.opencontainers.image.revision=$RELEASE_SHA org.opencontainers.image.source=$SOURCE_URL
COPY --from=dependencies --chown=www-data:www-data /var/www/html /var/www/html
COPY --from=assets --chown=www-data:www-data /build/public/build /var/www/html/public/build
COPY --chmod=755 docker/production/entrypoint.sh /usr/local/bin/app-entrypoint
COPY docker/production/health.php /usr/local/bin/app-health.php
USER www-data
ENTRYPOINT ["app-entrypoint"]
CMD ["php-fpm", "-F"]

FROM nginx:1.30.4-alpine@sha256:dc5069ad14f19660b141b21236140b91656bf89bbc3e2417c70ae650cd66104c AS web
ARG RELEASE_SHA
ARG SOURCE_URL
LABEL org.opencontainers.image.revision=$RELEASE_SHA org.opencontainers.image.source=$SOURCE_URL
COPY --from=assets /build/public /var/www/html/public
COPY docker/nginx/default.conf /etc/nginx/conf.d/default.conf
