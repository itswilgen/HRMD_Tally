#!/usr/bin/env bash
# Deploys a production copy of this project for use on the office LAN.
#
# The copy lives outside the dev folder, so `composer run dev` here keeps working.
# Run this again whenever you want the LAN copy to pick up your latest changes,
# then restart scripts/serve-lan.sh.
#
#   scripts/deploy-lan.sh            # copy, install, build, migrate, cache
#   DEST=/path PORT=8080 scripts/deploy-lan.sh
set -euo pipefail

SRC="$(cd "$(dirname "$0")/.." && pwd)"
DEST="${DEST:-$HOME/HRMD_TallySheet_server}"
PORT="${PORT:-8080}"
IP="$(ipconfig getifaddr en0 2>/dev/null || ipconfig getifaddr en1 2>/dev/null || echo 127.0.0.1)"

echo "==> Copying $SRC -> $DEST"
mkdir -p "$DEST"
# Excluded paths are neither copied nor deleted on the server copy, so its own
# .env, vendor/, node_modules/, logs and sessions survive a redeploy.
rsync -a --delete \
    --exclude '.git' --exclude 'node_modules' --exclude 'vendor' \
    --exclude '.env' --exclude 'public/hot' --exclude 'public/build' \
    --exclude 'storage/logs/*' --exclude 'storage/framework/*/*' \
    --exclude 'bootstrap/cache/*.php' \
    "$SRC/" "$DEST/"
mkdir -p "$DEST"/storage/framework/{cache/data,sessions,views} "$DEST/storage/logs"

set_env() { # set_env KEY VALUE  (replaces the line, or appends it)
    local key="$1" value="$2" file="$DEST/.env"
    if grep -q "^${key}=" "$file"; then
        KEY="$key" VALUE="$value" perl -pi -e 's/^\Q$ENV{KEY}\E=.*/$ENV{KEY}=$ENV{VALUE}/' "$file"
    else
        printf '%s=%s\n' "$key" "$value" >>"$file"
    fi
}

if [ ! -f "$DEST/.env" ]; then
    echo "==> Creating $DEST/.env from the dev .env (same database and APP_KEY)"
    cp "$SRC/.env" "$DEST/.env"
fi
set_env APP_ENV production
set_env APP_DEBUG false
set_env APP_URL "http://$IP:$PORT"
set_env LOG_LEVEL warning
# Keep the LAN copy's cache and queue apart from the dev copy's, since both
# share one database.
set_env CACHE_PREFIX hrmd_lan_cache_
set_env DB_QUEUE lan
chmod 600 "$DEST/.env"

cd "$DEST"
echo "==> composer install (no dev packages)"
composer install --no-dev --optimize-autoloader --no-interaction --quiet

echo "==> npm ci + build"
npm ci --no-audit --no-fund --loglevel=error
npm run build

echo "==> Migrating and caching"
php artisan migrate --force
php artisan optimize

echo
echo "Done. Start it with:  $SRC/scripts/serve-lan.sh"
echo "Then open:            http://$IP:$PORT"
