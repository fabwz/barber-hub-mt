#!/bin/sh
# Runs once, by the entrypoint of the postgres image, when the data volume is empty (never on later starts).
# Receives DB_ADMIN_PASSWORD and DB_PASSWORD from docker-compose.yml.
set -eu

: "${DB_ADMIN_PASSWORD:?DB_ADMIN_PASSWORD is not set}"
: "${DB_PASSWORD:?DB_PASSWORD is not set}"

psql -v ON_ERROR_STOP=1 --username "$POSTGRES_USER" --dbname postgres \
    -v owner_password="$DB_ADMIN_PASSWORD" -v app_password="$DB_PASSWORD" \
    -f /roles/roles.production.sql
