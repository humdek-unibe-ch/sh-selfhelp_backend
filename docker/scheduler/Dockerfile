FROM composer:2

RUN apk add --no-cache \
        $PHPIZE_DEPS \
        icu-dev \
        libzip-dev \
    && docker-php-ext-install \
        intl \
        pdo_mysql \
        zip

WORKDIR /app

COPY docker/scheduler/entrypoint.sh /usr/local/bin/selfhelp-scheduler-entrypoint

RUN chmod +x /usr/local/bin/selfhelp-scheduler-entrypoint

ENTRYPOINT ["/usr/local/bin/selfhelp-scheduler-entrypoint"]
