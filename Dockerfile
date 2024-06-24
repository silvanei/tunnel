FROM php:8.3.8-cli-alpine3.20

ENV COMPOSER_ALLOW_SUPERUSER=1 \
    TZ="America/Sao_Paulo"

COPY --from=composer:2.7.7 /usr/bin/composer /usr/local/bin/composer

RUN set -e \
    && apk update --no-cache \
    && apk add --no-cache \
        libstdc++ \
        libpq \
    && apk add --no-cache --virtual .phpize-deps \
        $PHPIZE_DEPS \
        curl-dev \
        linux-headers \
        brotli-dev \
        openssl-dev \
        pcre-dev \
        pcre2-dev \
        zlib-dev \
    && docker-php-ext-install \
        pdo_mysql \
        bcmath \
    && pecl install \
        sockets \
        inotify \
        xdebug \
    && pecl install --configureoptions 'enable-openssl="yes" enable-hook-curl="yes"' swoole \
    && docker-php-ext-enable \
        inotify \
        swoole \
        xdebug \
    #   Clear install
    && composer clear-cache \
    && apk del --no-network .phpize-deps \
    && docker-php-source delete \
    && rm -rf /var/cache/* \
    && rm -Rf /tmp/*

USER www-data