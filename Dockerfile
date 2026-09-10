# syntax=docker/dockerfile:1

########################################
# Base : FrankenPHP + extensions PHP
########################################
FROM dunglas/frankenphp:1-php8.3 AS base

RUN install-php-extensions \
        mongodb \
        intl \
        opcache \
        zip

# Ecoute en HTTP simple sur 8000 (pas d'auto-HTTPS car host vide)
ENV SERVER_NAME=:8000
ENV APP_ENV=prod
ENV APP_DEBUG=0

WORKDIR /app

COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

########################################
# Build : dependances + autoload optimise
########################################
FROM base AS build

ENV COMPOSER_ALLOW_SUPERUSER=1

COPY composer.json ./
RUN composer install \
        --no-dev --no-scripts --no-interaction \
        --prefer-dist --no-progress --optimize-autoloader

COPY . .
RUN composer dump-autoload --no-dev --optimize --classmap-authoritative \
    && composer run-script post-install-cmd || true

########################################
# Runtime : image finale
########################################
FROM base AS runtime

COPY --from=build /app /app

RUN set -eux; \
    mkdir -p var/cache var/log; \
    chown -R www-data:www-data var; \
    php bin/console cache:warmup --env=prod || true

EXPOSE 8000

# FrankenPHP sert public/ via le Caddyfile par defaut de l'image.
