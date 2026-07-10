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
# DB; unzip/git let Composer extract dist packages; cron runs scheduled console
# commands via the dedicated `cron` compose service).
RUN apt-get update \
    && apt-get install -y --no-install-recommends libmagickwand-dev unzip git cron \
    && printf '\n' | pecl install imagick \
    && docker-php-ext-enable imagick \
    && docker-php-ext-install pdo pdo_mysql mysqli \
    && a2enmod rewrite \
    && rm -rf /var/lib/apt/lists/*

# Serve from web/ (Yii2 document root) with .htaccess overrides allowed.
ENV APACHE_DOCUMENT_ROOT=/var/www/html/web
RUN sed -i 's|DocumentRoot /var/www/html|DocumentRoot /var/www/html/web|g' \
        /etc/apache2/sites-available/000-default.conf \
    && printf '<Directory /var/www/html/web>\n\
    AllowOverride All\n\
    Require all granted\n\
</Directory>\n' > /etc/apache2/conf-available/yii2.conf \
    && a2enconf yii2

# Composer (from the official composer image), available for both stages.
COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

WORKDIR /var/www/html

# ---- dev: local development ----------------------------------------------
# Nothing is copied in: docker-compose bind-mounts the project directory over
# /var/www/html, and `composer install` (with dev deps) is run by setup.sh.
# Extensions + Composer are already baked by `base`, so `docker-compose up` is
# fast and reproducible. Apache's default CMD (apache2-foreground) is inherited.
FROM base AS dev

# ---- prod: self-contained deployable image --------------------------------
FROM base AS prod

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

EXPOSE 80
