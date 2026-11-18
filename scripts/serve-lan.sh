#!/usr/bin/env bash
# Serves the LAN copy made by scripts/deploy-lan.sh to every PC on the network,
# with the queue worker that builds big prints and exports in the background
# (PROJECT_CONTEXT 5.7). Stop both with Ctrl+C, or by stopping the launchd job.
set -euo pipefail

DEST="${DEST:-$HOME/HRMD_TallySheet_server}"
PORT="${PORT:-8080}"
IP="$(ipconfig getifaddr en0 2>/dev/null || ipconfig getifaddr en1 2>/dev/null || echo 127.0.0.1)"

cd "$DEST"

# The queue worker reads the LAN copy's own queue (DB_QUEUE=lan, set by
# deploy-lan.sh), so it never picks up the dev copy's jobs. It restarts every
# hour to release memory; the loop starts it again.
(
    while true; do
        php artisan queue:work --tries=1 --sleep=3 --max-time=3600 --memory=1024 || sleep 5
    done
) &
WORKER_PID=$!

# When the server stops, the worker stops with it.
trap 'kill "$WORKER_PID" 2>/dev/null || true' EXIT INT TERM

echo "HRMD Tally Sheet on http://$IP:$PORT  (Ctrl+C to stop)"
echo "Background exports: queue worker running (pid $WORKER_PID)"

# Several workers so one slow PDF/Excel export does not block other HR users.
# Not `exec`: the trap above must stay to stop the worker.
PHP_CLI_SERVER_WORKERS="${WORKERS:-4}" php artisan serve --host=0.0.0.0 --port="$PORT" --no-reload
