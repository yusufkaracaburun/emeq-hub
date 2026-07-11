# syntax=docker/dockerfile:1

# ============================================================================
# base — FrankenPHP + PHP-extensies + composer. Code zit hier NIET in; dev
# bind-mount't de source, prod COPY't 'm pas in de prod-stage. Deze laag wordt
# dus alleen herbouwd bij een extensie-/toolwijziging, niet bij code-changes.
# ============================================================================
# Pin op PHP 8.4 (CLAUDE.md-constraint + host-parity); :latest schoof door naar 8.5.
FROM dunglas/frankenphp:php8.4 AS base

# predis = pure PHP → geen ext-redis nodig. pcntl: Octane/Horizon-signalen.
RUN install-php-extensions \
    pdo_pgsql \
    pcntl \
    intl \
    bcmath \
    zip \
    opcache

COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

WORKDIR /app

# Octane FrankenPHP worker-bootstrap. Deterministisch (geen 177MB binary-download
# zoals `octane:install` doet — dit image ís al FrankenPHP). CI-proof: bestaat ook
# zonder host-kopie; wordt in dev door de bind-mount overschreven met een identiek
# bestand, in prod door COPY niet verwijderd.
RUN mkdir -p public \
    && printf '%s\n' \
    '<?php' \
    '' \
    "\$_SERVER['APP_BASE_PATH'] = \$_ENV['APP_BASE_PATH'] ?? \$_SERVER['APP_BASE_PATH'] ?? __DIR__.'/..';" \
    "\$_SERVER['APP_PUBLIC_PATH'] = \$_ENV['APP_PUBLIC_PATH'] ?? \$_SERVER['APP_PUBLIC_PATH'] ?? __DIR__;" \
    '' \
    "require __DIR__.'/../vendor/laravel/octane/bin/frankenphp-worker.php';" \
    > public/frankenphp-worker.php

# ============================================================================
# dev — production-ini + dunne dev-overlay (debug + reload aan). Source +
# vendor komen via de bind-mount uit docker-compose.yml.
# ============================================================================
FROM base AS dev
RUN mv "$PHP_INI_DIR/php.ini-production" "$PHP_INI_DIR/php.ini"
COPY docker/php/dev-overlay.ini "$PHP_INI_DIR/conf.d/zz-dev.ini"

# ============================================================================
# vendor — composer-deps als losse stage, puur zodat de assets-stage erbij kan.
# ============================================================================
FROM base AS vendor
WORKDIR /app
COPY composer.json composer.lock ./
# --no-scripts: package:discover heeft de volledige app nodig, die hier niet staat.
# --no-autoloader: de prod-stage genereert de autoloader zelf, met de echte source.
RUN composer install --no-dev --no-scripts --no-autoloader --no-interaction --prefer-dist

# ============================================================================
# assets — gebouwde frontend voor prod (komt niet in dev; daar draait Vite-HMR).
# ============================================================================
FROM node:22-slim AS assets
WORKDIR /app
COPY package*.json ./
RUN npm ci
COPY . .
# resources/css/filament/admin/theme.css importeert
# ../../../../vendor/filament/filament/resources/css/theme.css. `vendor` staat in
# .dockerignore, dus zonder deze COPY draait de Vite-build op een lege map en
# faalt hij op een niet-resolvebare import. Lokaal valt dat niet op — daar staat
# vendor/ gewoon op schijf.
COPY --from=vendor /app/vendor ./vendor
RUN npm run build

# ============================================================================
# prod — immutable image: code gebakken, deps zonder dev, productie-ini,
# worker-mode zonder watch. TLS eindigt op de Cloudflare-rand; de origin serveert
# plain HTTP (auto_https off in docker/Caddyfile).
# ============================================================================
FROM base AS prod
RUN mv "$PHP_INI_DIR/php.ini-production" "$PHP_INI_DIR/php.ini"
# /etc/frankenphp/, niet /etc/caddy/: het entrypoint draait
# `frankenphp run --config /etc/frankenphp/Caddyfile`. Op de oude plek werd onze
# config genegeerd en gold de image-default — die doet auto-HTTPS en beantwoordde
# elke request met een 308 naar https://localhost.
COPY docker/Caddyfile /etc/frankenphp/Caddyfile
COPY . /app
COPY --from=assets /app/public/build /app/public/build
RUN composer install --no-dev --optimize-autoloader --no-interaction
