# syntax=docker/dockerfile:1
#
# SPDX-FileCopyrightText: 2026 Humdek, University of Bern
# SPDX-License-Identifier: MPL-2.0
#
# SelfHelp backend production images. One multi-stage build, three published targets
# (the names + digests the unified registry's core release points at):
#
#   docker build --target backend   -f docker/Dockerfile -t selfhelp-backend   .
#   docker build --target worker    -f docker/Dockerfile -t selfhelp-worker    .
#   docker build --target scheduler -f docker/Dockerfile -t selfhelp-scheduler .
#
# Published by .github/workflows/docker-release.yml as
#   ghcr.io/humdek-unibe-ch/selfhelp-{backend,worker,scheduler}:<version>.
#
# The web app is served by FrankenPHP (Caddy + PHP, no hand-written nginx/caddy
# config) on :8080 behind the SelfHelp Manager's Traefik proxy. The worker runs the
# Symfony Messenger `plugin_ops` consumer; the scheduler runs the due-jobs loop.

ARG FRANKENPHP_VERSION=1-php8.4

# --- base: runtime + PHP extensions ----------------------------------------
FROM dunglas/frankenphp:${FRANKENPHP_VERSION} AS base

ENV APP_ENV=prod \
    APP_DEBUG=0 \
    COMPOSER_ALLOW_SUPERUSER=1 \
    COMPOSER_NO_INTERACTION=1

WORKDIR /app

# curl is used by the container HEALTHCHECK; the rest are PHP extensions the app
# needs (Doctrine/MySQL, intl, zip) plus opcache for production.
RUN apt-get update \
    && apt-get install -y --no-install-recommends curl \
    && rm -rf /var/lib/apt/lists/* \
    && install-php-extensions intl pdo_mysql zip opcache

RUN cp "$PHP_INI_DIR/php.ini-production" "$PHP_INI_DIR/php.ini"
COPY docker/php/conf.d/app.prod.ini $PHP_INI_DIR/conf.d/zz-app.ini
COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

# --- vendor: install prod dependencies + optimized autoloader --------------
FROM base AS vendor

# Resolve dependencies first (cache layer), then add the source and dump a
# classmap-authoritative autoloader. `--no-scripts` avoids running Symfony
# auto-scripts (cache:clear/assets:install) at build time, when no runtime
# secrets/env exist; the cache is warmed lazily at container start instead.
COPY composer.json composer.lock ./
RUN composer install --no-dev --no-scripts --prefer-dist --no-progress --no-autoloader

COPY . .
RUN composer dump-autoload --no-dev --classmap-authoritative \
    && mkdir -p var/cache var/log \
    && chown -R www-data:www-data var

# --- backend: FrankenPHP web app -------------------------------------------
FROM base AS backend

ENV SERVER_NAME=:8080
COPY --from=vendor --chown=www-data:www-data /app /app
EXPOSE 8080
USER www-data
HEALTHCHECK --interval=30s --timeout=5s --start-period=45s --retries=3 \
    CMD curl -fsS "http://127.0.0.1:8080/cms-api/v1/health" || exit 1

# --- worker: Symfony Messenger consumer for plugin operations --------------
FROM base AS worker

COPY --from=vendor --chown=www-data:www-data /app /app
USER www-data
ENTRYPOINT []
CMD ["php", "bin/console", "messenger:consume", "plugin_ops", "--time-limit=3600", "--memory-limit=256M", "-v"]

# --- scheduler: due scheduled-jobs runner loop -----------------------------
FROM base AS scheduler

COPY --from=vendor --chown=www-data:www-data /app /app
COPY --chmod=0755 docker/scheduler/prod-entrypoint.sh /usr/local/bin/selfhelp-scheduler
USER www-data
ENTRYPOINT []
CMD ["/usr/local/bin/selfhelp-scheduler"]
