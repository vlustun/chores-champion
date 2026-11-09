# ---- shared base: PHP + extensions -------------------------------------------
FROM php:8.3-fpm AS fpm-base
RUN apt-get update && apt-get install -y --no-install-recommends \
        git unzip libicu-dev libzip-dev libonig-dev libfcgi-bin \
    && docker-php-ext-install intl pdo_mysql zip opcache \
    && pecl install apcu && docker-php-ext-enable apcu \
    && rm -rf /var/lib/apt/lists/*
COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

# ---- production php-fpm image (build with context = project root) ------------
#   docker build -f docker/php/Dockerfile --target fpm .
FROM fpm-base AS fpm
ENV APP_ENV=prod APP_DEBUG=0 COMPOSER_ALLOW_SUPERUSER=1
WORKDIR /app

RUN { \
        echo "opcache.enable=1"; \
        echo "opcache.memory_consumption=128"; \
        echo "opcache.max_accelerated_files=20000"; \
        echo "opcache.validate_timestamps=0"; \
        echo "expose_php=0"; \
    } > /usr/local/etc/php/conf.d/zz-prod.ini \
    && { echo "[www]"; echo "ping.path=/ping"; echo "pm.status_path=/status"; } > /usr/local/etc/php-fpm.d/zz-health.conf

COPY composer.json composer.lock symfony.lock ./
RUN composer install --no-dev --no-scripts --no-autoloader --no-progress --prefer-dist

COPY . .
# Build-time placeholders only: real values come from the container environment at runtime.
RUN APP_SECRET=build-only DATABASE_URL="mysql://u:p@db:3306/x?serverVersion=8.4" \
    && export APP_SECRET DATABASE_URL \
    && composer install --no-dev --no-progress --optimize-autoloader --classmap-authoritative \
    && php bin/console importmap:install \
    && php bin/console asset-map:compile \
    && php bin/console cache:clear \
    && php bin/console cache:warmup \
    && mkdir -p var/share \
    && chown -R www-data:www-data var \
    && chown -R www-data:www-data vendor
USER www-data
EXPOSE 9000
CMD ["php-fpm", "-F"]

# ---- dev php-fpm image with Xdebug (used by compose.xdebug.yaml) -------------
#   docker build -f docker/php/Dockerfile --target fpm-dev .
FROM fpm-base AS fpm-dev
ENV APP_ENV=dev APP_DEBUG=1 COMPOSER_ALLOW_SUPERUSER=1 XDEBUG_MODE=debug \
    COMPOSER_HOME=/tmp/composer HOME=/tmp
WORKDIR /app
RUN pecl install xdebug && docker-php-ext-enable xdebug \
    && { \
        echo 'xdebug.mode=${XDEBUG_MODE:-debug}'; \
        echo "xdebug.client_host=host.docker.internal"; \
        echo "xdebug.client_port=9003"; \
        echo "xdebug.start_with_request=trigger"; \
    } > /usr/local/etc/php/conf.d/zz-xdebug.ini \
    && { echo "[www]"; echo "ping.path=/ping"; echo "pm.status_path=/status"; } > /usr/local/etc/php-fpm.d/zz-health.conf
COPY composer.json composer.lock symfony.lock ./
RUN composer install --no-scripts --no-autoloader --no-progress --prefer-dist
COPY . .
RUN composer dump-autoload --no-scripts \
    && mkdir -p var /tmp/composer && chmod -R a+rwX var /tmp/composer \
    && chown -R www-data:www-data var \
    && chown -R www-data:www-data vendor
# Default user; compose.xdebug.yaml overrides it with the host UID:GID.
USER www-data
EXPOSE 9000
CMD ["php-fpm", "-F"]

# ---- dev/tools image (default target: `docker compose --profile tools`) ------
FROM php:8.3-cli AS cli

RUN apt-get update && apt-get install -y --no-install-recommends \
        git unzip libicu-dev libzip-dev libonig-dev \
    && docker-php-ext-install intl pdo_mysql zip opcache pcntl \
    && pecl install apcu pcov xdebug \
    && docker-php-ext-enable apcu pcov xdebug \
    && echo "apc.enable_cli=1" >> /usr/local/etc/php/conf.d/docker-php-ext-apcu.ini \
    && echo 'xdebug.mode=${XDEBUG_MODE:-off}' > /usr/local/etc/php/conf.d/zz-xdebug.ini \
    && echo "xdebug.client_host=host.docker.internal" >> /usr/local/etc/php/conf.d/zz-xdebug.ini \
    && echo "xdebug.client_port=9003" >> /usr/local/etc/php/conf.d/zz-xdebug.ini \
    && echo "xdebug.start_with_request=trigger" >> /usr/local/etc/php/conf.d/zz-xdebug.ini \
    && echo "pcov.enabled=0" >> /usr/local/etc/php/conf.d/docker-php-ext-pcov.ini \
    && rm -rf /var/lib/apt/lists/*

COPY --from=composer:2 /usr/bin/composer /usr/bin/composer
WORKDIR /app
