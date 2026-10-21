FROM composer:2.8.12@sha256:5248900ab8b5f7f880c2d62180e40960cd87f60149ec9a1abfd62ac72a02577c AS composer
FROM php:8.2.35-cli-alpine@sha256:991b0d92a3c405629a80ee58019d215876e1b478523134d2ca91f2d5f6c2d89c

RUN apk add --no-cache freetype libjpeg-turbo libpng libpq libzip \
    && apk add --no-cache --virtual .build-deps $PHPIZE_DEPS freetype-dev libjpeg-turbo-dev libpng-dev postgresql-dev libzip-dev \
    && docker-php-ext-configure gd --with-freetype --with-jpeg \
    && docker-php-ext-install -j2 gd mysqli pdo_mysql pgsql pdo_pgsql zip \
    && apk del .build-deps

RUN apk add --no-cache poppler-utils font-liberation qpdf zip 7zip

COPY --from=composer /usr/bin/composer /usr/local/bin/composer
ENV COMPOSER_ALLOW_SUPERUSER=1
WORKDIR /opt/test-tools
COPY composer.json composer.lock ./
RUN composer install --no-interaction --no-progress --prefer-dist --no-scripts \
    && composer check-platform-reqs

WORKDIR /workspace/backend
CMD ["php", "/opt/test-tools/vendor/bin/phpunit", "--configuration", "/workspace/backend/test/phpunit.xml"]