#!/usr/bin/env bash
# Container entrypoint: wait for the database, prepare Laravel, then exec the CMD.
# Safe to run on every start.
set -e
cd /var/www/html

echo "[entrypoint] waiting for the database ${DB_HOST}:${DB_PORT:-5432} ..."
for _ in $(seq 1 60); do
    nc -z "${DB_HOST}" "${DB_PORT:-5432}" 2>/dev/null && break
    sleep 2
done

# No APP_KEY given (the usual case on your own machine): make one once and keep it in the files volume.
# It must stay the same between restarts: it encrypts the sessions and the secrets saved in Settings.
if [ -z "${APP_KEY}" ]; then
    KEYFILE=storage/app/private/.app_key
    [ -s "$KEYFILE" ] || php -r 'echo "base64:".base64_encode(random_bytes(32));' > "$KEYFILE"
    chmod 600 "$KEYFILE"
    export APP_KEY="$(cat "$KEYFILE")"
fi

# Not --isolated: its lock lives in the cache, and the cache is a database table that a fresh database
# does not have yet (the first start failed on exactly that). Only this one container migrates anyway.
php artisan migrate --force

# the demo accounts and a few sample emails (DatabaseSeeder creates only what is missing)
if [ "${SEED_DEMO:-true}" = "true" ]; then
    php artisan db:seed --force
fi

php artisan config:clear >/dev/null 2>&1 || true
php artisan route:cache  >/dev/null 2>&1 || true
php artisan view:cache   >/dev/null 2>&1 || true

chown -R www-data:www-data storage bootstrap/cache 2>/dev/null || true

echo "[entrypoint] ready on ${APP_URL}"
exec "$@"
