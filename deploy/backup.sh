#!/usr/bin/env bash
# Daily backup of BarberHub (#136): a dump of the database and a copy of the uploaded images, with short retention.
#   ./backup.sh                       writes to /var/backups/barberhub and deletes what is older than 7 days
#   BACKUP_DIR=/mnt/x RETENTION_DAYS=14 ./backup.sh
# Cron (as the user that runs docker), 03:15 every day:
#   15 3 * * * /opt/barberhub/deploy/backup.sh >> /var/log/barberhub-backup.log 2>&1
# The files stay ON THIS SERVER: copying them elsewhere and testing a restore are manual steps (docs/deployment.md).
set -euo pipefail

cd "$(dirname "${BASH_SOURCE[0]}")"

BACKUP_DIR="${BACKUP_DIR:-/var/backups/barberhub}"
RETENTION_DAYS="${RETENTION_DAYS:-7}"
[[ "$RETENTION_DAYS" =~ ^[0-9]+$ ]] || { echo "ERROR: RETENTION_DAYS must be a number." >&2; exit 1; }

# The backups hold personal data of the customers: only the user that runs this script may read them.
umask 077
mkdir -p "$BACKUP_DIR"

STAMP="$(date +%Y%m%d-%H%M%S)"
DB_FILE="$BACKUP_DIR/barberhub-db-$STAMP.dump"
MEDIA_FILE="$BACKUP_DIR/barberhub-media-$STAMP.tar.gz"

echo "[$(date -Is)] Backup started"

# pg_dump runs as the superuser of the container: the tables force row-level security, and the owner role would get only
# the rows its policies allow, an incomplete dump. The custom format (-Fc) is compressed and restored with pg_restore.
docker compose exec -T db pg_dump --username postgres --dbname barberhub --format=custom > "$DB_FILE.tmp"
# A dump that pg_restore cannot list is useless: stop before deleting any old backup.
docker compose exec -T db pg_restore --list < "$DB_FILE.tmp" > /dev/null
mv "$DB_FILE.tmp" "$DB_FILE"

docker compose exec -T app tar -C /var/www/html/storage/app -czf - public > "$MEDIA_FILE.tmp"
tar -tzf "$MEDIA_FILE.tmp" > /dev/null
mv "$MEDIA_FILE.tmp" "$MEDIA_FILE"

# Retention: only our own files, older than RETENTION_DAYS days.
find "$BACKUP_DIR" -maxdepth 1 -type f \( -name 'barberhub-db-*.dump' -o -name 'barberhub-media-*.tar.gz' \) -mtime "+$RETENTION_DAYS" -print -delete

echo "[$(date -Is)] Backup finished: $(basename "$DB_FILE") ($(du -h "$DB_FILE" | cut -f1)), $(basename "$MEDIA_FILE") ($(du -h "$MEDIA_FILE" | cut -f1))"
