#!/bin/sh
# Prepares the app before Apache starts: checks the key, migrates, syncs roles
# and caches the config. Other commands (queue worker, artisan) run as given.
set -e
cd /var/www/html

if [ "$1" = "apache2-foreground" ]; then
    if [ -z "$APP_KEY" ]; then
        echo "APP_KEY is empty in .env. Make one with:" >&2
        echo "  docker compose run --rm --no-deps app php artisan key:generate --show" >&2
        echo "then paste the value after APP_KEY= in .env and run: docker compose up -d" >&2
        exit 1
    fi

    # The database container may still be starting the first time.
    tries=0
    until php artisan migrate --force; do
        tries=$((tries + 1))
        if [ "$tries" -ge 10 ]; then
            echo "Could not reach the database after 10 tries." >&2
            exit 1
        fi
        echo "Database not ready, retrying in 3 seconds..."
        sleep 3
    done

    # Roles come from the code, so they are synced on every start. Offices,
    # leave types, holidays and the first admin are hrmd:install's job.
    php artisan db:seed --class='Database\Seeders\RoleSeeder' --force
    php artisan optimize
    chown -R www-data:www-data storage bootstrap/cache
fi

exec "$@"
