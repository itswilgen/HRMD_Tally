#!/bin/sh
# Runs in the "backup" container: one pg_dump at start, then one every 24 hours.
# Restore steps are in README.md ("Install with Docker" > Restore a backup).
set -eu

until pg_isready -q; do sleep 2; done

while true; do
    file="/backups/hrmd-$(date +%Y-%m-%d-%H%M).dump"

    # Written to .tmp first, so a half-written dump never looks like a good one.
    if pg_dump --format=custom --file="$file.tmp"; then
        mv "$file.tmp" "$file"
        echo "$(date '+%F %T') Backup saved: $(basename "$file") ($(du -h "$file" | cut -f1))"
    else
        rm -f "$file.tmp"
        echo "$(date '+%F %T') BACKUP FAILED" >&2
    fi

    find /backups -name 'hrmd-*.dump' -mtime +"${BACKUP_KEEP_DAYS}" -delete

    sleep 86400
done
