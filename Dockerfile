# Single multi-stage Dockerfile — one source of truth for the environment.
#
#   base : shared runtime (PHP extensions, Apache, Composer). Never used directly.
#   dev  : local development — code + vendor come from a bind mount (see
#          docker-compose.yml, `target: dev`); everything else is baked in so
#          the container starts instantly instead of installing Imagick on every
#          `up` like the old inline command did.
#   prod : self-contained, deployable image — production deps + app code baked
#          in, no volumes. Built by the CD pipeline (`target: prod`).

# ---- base: shared runtime -------------------------------------------------
FROM php:8.5-apache AS base

# System deps + PHP extensions (imagick for photo uploads, pdo_mysql/mysqli for
# DB, pcntl so the queue worker can shut down gracefully on SIGTERM; unzip/git
# let Composer extract dist packages; cron runs scheduled console commands via
# the dedicated `cron` compose service).
RUN apt-get update \
    && apt-get install -y --no-install-recommends libmagickwand-dev unzip git cron \
    && printf '\n' | pecl install imagick \
    && docker-php-ext-enable imagick \
    && docker-php-ext-install pdo pdo_mysql mysqli pcntl \
    && a2enmod rewrite headers \
    && rm -rf /var/lib/apt/lists/*

# Code-coverage driver, in its own layer so a pcov failure never invalidates the
# expensive imagick layer above. pcov (not Xdebug) because it exists only to
# collect coverage and costs a fraction of Xdebug's overhead.
#
# It is compiled in but DISABLED by default (`pcov.enabled=0`): at 0 pcov does
# not shadow-copy opcode arrays, so `make test` — the TDD inner loop — pays
# literally nothing. Coverage runs opt in per-process with `php -d
# pcov.enabled=1` (see the `coverage` target in the Makefile).
#
# CI installs its own driver via shivammathur/setup-php (`coverage: pcov` in
# .github/workflows/ci.yml) — keep the two in step.
RUN pecl install pcov \
    && docker-php-ext-enable pcov \
    && printf 'pcov.enabled=0\npcov.directory=/var/www/html\npcov.exclude="~/(vendor|tests|runtime)/~"\n' \
        >> /usr/local/etc/php/conf.d/docker-php-ext-pcov.ini

# Serve from web/ (Yii2 document root) with .htaccess overrides allowed.
ENV APACHE_DOCUMENT_ROOT=/var/www/html/web
RUN sed -i 's|DocumentRoot /var/www/html|DocumentRoot /var/www/html/web|g' \
        /etc/apache2/sites-available/000-default.conf \
    && printf '<Directory /var/www/html/web>\n\
    AllowOverride All\n\
    Require all granted\n\
</Directory>\n' > /etc/apache2/conf-available/yii2.conf \
    && a2enconf yii2

# PHP runtime configuration. The official image ships *no* php.ini, so without
# this the runtime falls back to PHP's compiled-in defaults — display_errors
# among them, which in production prints internals into the response body. The
# production baseline is installed here and stage-specific files layer on top;
# `zz-` sorts after the docker-php-ext-*.ini files so these win.
COPY docker/php/app.ini /usr/local/etc/php/conf.d/zz-app.ini
RUN mv "$PHP_INI_DIR/php.ini-production" "$PHP_INI_DIR/php.ini"

# Composer (from the official composer image), available for both stages.
COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

WORKDIR /var/www/html

# ---- dev: local development ----------------------------------------------
# Nothing is copied in: docker-compose bind-mounts the project directory over
# /var/www/html, and `composer install` (with dev deps) is run by setup.sh.
# Extensions + Composer are already baked by `base`, so `docker-compose up` is
# fast and reproducible. Apache's default CMD (apache2-foreground) is inherited.
FROM base AS dev

# Visible errors, and an opcache that notices edits under the bind mount.
COPY docker/php/dev.ini /usr/local/etc/php/conf.d/zzz-dev.ini

# ---- prod: self-contained deployable image --------------------------------
FROM base AS prod

# Immutable code: opcache stops revalidating, caches get production sizes.
COPY docker/php/prod.ini /usr/local/etc/php/conf.d/zzz-prod.ini

# Install production dependencies first (better layer caching), no dev tooling.
# --no-scripts skips Yii's postInstall (cookie-key generation) — the key comes
# from the COOKIE_VALIDATION_KEY env var at runtime instead.
COPY composer.json composer.lock ./
RUN composer install --no-dev --no-interaction --prefer-dist \
        --no-progress --no-scripts --optimize-autoloader

# Application code (vendor/tests/.env excluded via .dockerignore).
COPY . .

# The web server must be able to write runtime + uploads.
RUN chown -R www-data:www-data runtime web/assets web/uploads \
    && chmod -R 775 runtime web/assets web/uploads

# The application already answers /health with a real database check; this is
# what makes an orchestrator act on it — a container whose PHP is up but whose
# database is unreachable is not ready to take traffic, and without a
# HEALTHCHECK nothing but a caller would ever find out.
HEALTHCHECK --interval=30s --timeout=5s --start-period=20s --retries=3 \
    CMD php -r 'exit(@file_get_contents("http://127.0.0.1/health") !== false ? 0 : 1);'

EXPOSE 80
