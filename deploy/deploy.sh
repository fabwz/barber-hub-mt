#!/usr/bin/env bash
# Manual deployment of BarberHub on the server (#136). Idempotent: running it again with nothing new changes nothing visible.
#   ./deploy.sh              pulls main, builds, migrates, restarts what changed and checks /salud
#   ./deploy.sh --no-pull    deploys what is already checked out (to redeploy, or after checking out another commit)
# Run it from anywhere; it works in the folder where it lives. Needs deploy/.env (copy of .env.production.example).
set -euo pipefail

cd "$(dirname "${BASH_SOURCE[0]}")"

PULL=1
[[ "${1:-}" == "--no-pull" ]] && PULL=0

[[ -f .env ]] || { echo "ERROR: deploy/.env does not exist. Copy .env.production.example and fill it in." >&2; exit 1; }

# Reads one variable of .env without executing the file.
read_env() {
  grep -E "^$1=" .env | tail -n 1 | cut -d= -f2- | sed -e "s/^['\"]//" -e "s/['\"]\$//"
}

DOMAIN="$(read_env DOMAIN)"
[[ -n "$DOMAIN" ]] || { echo "ERROR: DOMAIN is empty in deploy/.env." >&2; exit 1; }

if [[ "$PULL" == 1 ]]; then
  echo "==> Updating the code (fast-forward only)"
  git -C .. pull --ff-only
fi

# Every image carries the commit it was built from, so a rollback is `IMAGE_TAG=<commit> docker compose up -d`.
IMAGE_TAG="$(git -C .. rev-parse --short HEAD)"
NODE_VERSION="$(tr -d 'v \r\n' < ../barberhub-web/.nvmrc)"
export IMAGE_TAG NODE_VERSION
echo "==> Deploying commit $IMAGE_TAG (Node $NODE_VERSION) to https://$DOMAIN"

echo "==> Building the images"
docker compose build
# `latest` always names the running version, so plain `docker compose` commands (logs, exec, ps) keep working.
docker tag "barberhub-api:$IMAGE_TAG" barberhub-api:latest
docker tag "barberhub-web:$IMAGE_TAG" barberhub-web:latest

echo "==> Starting the database and waiting until it answers"
docker compose up -d --wait db

echo "==> Applying the migrations with the owner role"
docker compose run --rm --no-deps app php artisan migrate --database=pgsql_admin --force

echo "==> Starting the services (the entrypoint caches the configuration, the routes and the events)"
docker compose up -d --remove-orphans

echo "==> Telling the workers to restart after their current job"
docker compose run --rm --no-deps app php artisan queue:restart

echo "==> Checking https://$DOMAIN/salud"
# curl retries on connection errors and on 5xx while the proxy gets its certificate and php-fpm starts.
if body="$(curl --fail --silent --show-error --retry 20 --retry-delay 3 --retry-connrefused --max-time 10 "https://$DOMAIN/salud")"; then
  echo "OK: /salud answered 200 $body"
else
  echo "ERROR: /salud did not answer 200. See: docker compose logs --tail=100 app proxy" >&2
  exit 1
fi

echo "==> Done. Commit $IMAGE_TAG is live."
