FROM dunglas/frankenphp:1-php8.4-alpine

RUN apk add --no-cache git unzip bash sqlite
RUN install-php-extensions intl zip pdo_sqlite

COPY --from=composer:2 /usr/bin/composer /usr/bin/composer
RUN git config --global --add safe.directory /app && \
    git config --global --add safe.directory /var/audit-kit-bundle

WORKDIR /app

ENV COMPOSER_ALLOW_SUPERUSER=1
ENV PATH="/app/vendor/bin:${PATH}"
ENV SERVER_NAME=:80

COPY docker/frankenphp/Caddyfile /etc/frankenphp/Caddyfile
COPY docker/frankenphp/Caddyfile.dev /etc/frankenphp/Caddyfile.dev

RUN echo '#!/bin/sh' > /usr/local/bin/docker-entrypoint.sh && \
    echo 'set -e' >> /usr/local/bin/docker-entrypoint.sh && \
    echo 'git config --global --add safe.directory /app 2>/dev/null || true' >> /usr/local/bin/docker-entrypoint.sh && \
    echo 'git config --global --add safe.directory /var/audit-kit-bundle 2>/dev/null || true' >> /usr/local/bin/docker-entrypoint.sh && \
    echo 'mkdir -p /app/var/cache /app/var/log /app/var/data' >> /usr/local/bin/docker-entrypoint.sh && \
    echo 'chmod -R 777 /app/var 2>/dev/null || true' >> /usr/local/bin/docker-entrypoint.sh && \
    echo 'if [ "${APP_ENV:-prod}" = "dev" ]; then cp /etc/frankenphp/Caddyfile.dev /etc/frankenphp/Caddyfile; fi' >> /usr/local/bin/docker-entrypoint.sh && \
    echo 'exec frankenphp run --config /etc/frankenphp/Caddyfile --adapter caddyfile' >> /usr/local/bin/docker-entrypoint.sh && \
    chmod +x /usr/local/bin/docker-entrypoint.sh

ENTRYPOINT ["docker-entrypoint.sh"]
