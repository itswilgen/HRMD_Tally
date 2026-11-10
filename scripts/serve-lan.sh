#!/usr/bin/env bash
# Serves the LAN copy made by scripts/deploy-lan.sh to every PC on the network.
# Stop it with Ctrl+C.
set -euo pipefail

DEST="${DEST:-$HOME/HRMD_TallySheet_server}"
PORT="${PORT:-8080}"
IP="$(ipconfig getifaddr en0 2>/dev/null || ipconfig getifaddr en1 2>/dev/null || echo 127.0.0.1)"

cd "$DEST"
echo "HRMD Tally Sheet on http://$IP:$PORT  (Ctrl+C to stop)"
# Several workers so one slow PDF/Excel export does not block other HR users.
PHP_CLI_SERVER_WORKERS="${WORKERS:-4}" exec php artisan serve --host=0.0.0.0 --port="$PORT" --no-reload
