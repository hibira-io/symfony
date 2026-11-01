#!/bin/sh
set -e

mkdir -p var/data

# SQLite lives in var/, shared by the web server and the Messenger worker.
php bin/console doctrine:schema:update --force --complete --no-interaction >/dev/null
php bin/console messenger:setup-transports --no-interaction >/dev/null

exec "$@"
