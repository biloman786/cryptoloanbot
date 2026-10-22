#!/bin/sh
set -e

# The bind mount replaces the image's users.json with the host's copy, which
# strips the permissions baked into the image. Re-apply them on every start.
STATE_FILE="${STATE_FILE:-/var/www/html/users.json}"

mkdir -p "$(dirname "$STATE_FILE")"
touch "$STATE_FILE"
chown www-data:www-data "$STATE_FILE"
chmod 664 "$STATE_FILE"

exec docker-php-entrypoint "$@"
