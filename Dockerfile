# syntax=docker/dockerfile:1
#
# Production image for the HRMD Leave Tally Sheet System.
# Build and run it with compose.yaml; see "Install with Docker" in README.md.

# ---------------------------------------------------------------------------
# base: PHP 8.4 + Apache, with the extensions composer.lock needs
# (pdo_pgsql for PostgreSQL, gd and zip for the PDF and Excel exports).
# ---------------------------------------------------------------------------
FROM php:8.4-apache AS base

RUN apt-get update \
    && apt-get install -y --no-install-recommends \
        libpq-dev libzip-dev libpng-dev libjpeg62-turbo-dev libfreetype6-dev unzip \
    && docker-php-ext-configure gd --with-freetype --with-jpeg \
    && docker-php-ext-install -j"$(nproc)" pdo_pgsql zip gd opcache \
    && rm -rf /var/lib/apt/lists/*

RUN mv "$PHP_INI_DIR/php.ini-production" "$PHP_INI_DIR/php.ini" \
    && a2enmod rewrite headers
COPY docker/php.ini "$PHP_INI_DIR/conf.d/zz-hrmd.ini"
COPY docker/apache.conf /etc/apache2/sites-available/000-default.conf

WORKDIR /var/www/html

# ---------------------------------------------------------------------------
# vendor: Composer packages, without the dev tools (Pest, Larastan, Pint...)
# ---------------------------------------------------------------------------
FROM base AS vendor
COPY --from=composer:2 /usr/bin/composer /usr/bin/composer
COPY composer.json composer.lock ./
RUN composer install --no-dev --no-scripts --no-autoloader --no-interaction --prefer-dist

# ---------------------------------------------------------------------------
# assets: the React/Tailwind build (public/build)
# ---------------------------------------------------------------------------
FROM node:22-bookworm-slim AS assets
WORKDIR /app
COPY package.json package-lock.json ./
RUN npm ci --no-audit --no-fund
# app.tsx imports ziggy-js, which is served from the Composer package.
COPY --from=vendor /var/www/html/vendor/tightenco/ziggy ./vendor/tightenco/ziggy
COPY resources ./resources
COPY vite.config.js tsconfig.json components.json ./
# There is no .env inside the image, so Vite needs the app name passed in.
ARG VITE_APP_NAME="HRMD Leave Tally Sheet"
ENV VITE_APP_NAME=${VITE_APP_NAME}
RUN npm run build

# ---------------------------------------------------------------------------
# app: the final image
# ---------------------------------------------------------------------------
FROM base AS app
COPY --from=composer:2 /usr/bin/composer /usr/bin/composer
COPY . .
COPY --from=vendor /var/www/html/vendor ./vendor
COPY --from=assets /app/public/build ./public/build
RUN composer dump-autoload --optimize --no-dev --no-interaction \
    && chown -R www-data:www-data storage bootstrap/cache \
    && chmod +x docker/entrypoint.sh

EXPOSE 80
HEALTHCHECK --interval=30s --timeout=5s --start-period=60s --retries=3 \
    CMD php -r "exit(@file_get_contents('http://localhost/up') === false ? 1 : 0);"

ENTRYPOINT ["docker/entrypoint.sh"]
CMD ["apache2-foreground"]
