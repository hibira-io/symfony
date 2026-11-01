# The live board on FrankenPHP. Build from the repository root, so the
# bundle in this repo is installed instead of the Packagist release:
#
#   docker compose -f example/compose.yaml up --build
FROM dunglas/frankenphp:1-php8.4

RUN install-php-extensions pdo_sqlite intl opcache zip
COPY --from=composer/composer:2-bin /composer /usr/bin/composer

ENV APP_ENV=prod \
    COMPOSER_ALLOW_SUPERUSER=1 \
    SERVER_NAME=:80

COPY . /bundle
WORKDIR /app
RUN cp -a /bundle/example/. /app \
 && rm -rf vendor var node_modules composer.lock .env.local public/assets assets/vendor \
 && composer config repositories.hibira '{"type": "path", "url": "/bundle", "options": {"symlink": false}}' \
 && composer install --no-dev --optimize-autoloader --no-interaction --no-progress --no-scripts \
 && php bin/console importmap:install \
 && php bin/console asset-map:compile \
 && php bin/console cache:warmup \
 && mkdir -p var/data

COPY example/docker-entrypoint.sh /usr/local/bin/docker-entrypoint
RUN chmod +x /usr/local/bin/docker-entrypoint
ENTRYPOINT ["docker-entrypoint"]
CMD ["frankenphp", "run", "--config", "/etc/frankenphp/Caddyfile"]
