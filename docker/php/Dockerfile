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
    && chown -R www-data:www-data var

USER www-data
EXPOSE 9000
CMD ["php-fpm", "-F"]

# ---- dev/tools image (default target: `docker compose --profile tools`) ------
FROM php:8.3-cli AS cli

RUN apt-get update && apt-get install -y --no-install-recommends \
        git unzip libicu-dev libzip-dev libonig-dev \
    && docker-php-ext-install intl pdo_mysql zip opcache pcntl \
    && pecl install apcu pcov \
    && docker-php-ext-enable apcu pcov \
    && echo "apc.enable_cli=1" >> /usr/local/etc/php/conf.d/docker-php-ext-apcu.ini \
    && echo "pcov.enabled=0" >> /usr/local/etc/php/conf.d/docker-php-ext-pcov.ini \
    && rm -rf /var/lib/apt/lists/*

COPY --from=composer:2 /usr/bin/composer /usr/bin/composer
WORKDIR /app
