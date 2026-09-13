# syntax=docker/dockerfile:1
ARG COMPOSER_IMAGE=composer:2.10.3@sha256:d8f6343d3fae98107426bc49163ccad46ef85aabd4a27d80a74401fab4aba332
FROM ${COMPOSER_IMAGE} AS composer

FROM php:8.4-fpm-bookworm@sha256:075b11566518bfa979bb9f2fe2e5359148326d659b15a2f414c2c305a0479a4e AS development

# The base image already includes most Laravel extensions, including mbstring.
RUN apt-get update \
    && apt-get install -y --no-install-recommends git unzip libpq-dev libicu-dev libzip-dev \
    && docker-php-ext-install -j"$(nproc)" pdo_pgsql intl zip opcache \
    && rm -rf /var/lib/apt/lists/*

COPY --from=composer /usr/bin/composer /usr/local/bin/composer
COPY docker/php/development.ini /usr/local/etc/php/conf.d/development.ini

ARG LOCAL_UID=1000
ARG LOCAL_GID=1000
RUN groupadd --gid "${LOCAL_GID}" developer \
    && useradd --uid "${LOCAL_UID}" --gid developer --create-home --shell /bin/bash developer

WORKDIR /var/www/html
USER developer

CMD ["php-fpm", "-F"]
