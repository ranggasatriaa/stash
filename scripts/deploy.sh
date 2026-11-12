#!/usr/bin/env bash
# Non-destructive production deployment on the VPS (run from the repo root).
#   - never drops/resets data; migrations are additive and run once
#   - takes a pre-deploy database dump (scripts/backup.sh --db-only)
#   - rebuilds images, migrates, restarts workers gracefully, health-checks
set -euo pipefail
cd "$(dirname "$0")/.."

[ -f .env ] || { echo ".env missing (copy .env.example and fill it in)" >&2; exit 1; }
grep -q '^APP_ENV=production' .env || { echo "APP_ENV must be production" >&2; exit 1; }
grep -q '^APP_DEBUG=false' .env || { echo "APP_DEBUG must be false" >&2; exit 1; }

TAG="$(git rev-parse --short HEAD 2>/dev/null || date +%Y%m%d%H%M%S)"
export STASH_IMAGE_TAG="$TAG"

echo "==> Pre-deploy database backup"
if docker compose ps --status running postgres >/dev/null 2>&1 && [ -n "$(docker compose ps -q postgres)" ]; then
  ./scripts/backup.sh --db-only
else
  echo "    (postgres not running yet — first deployment, skipping)"
fi

echo "==> Building images ($TAG)"
docker compose build --pull

echo "==> Starting data services"
docker compose up -d postgres redis

echo "==> Running migrations (once, in a one-off container)"
docker compose run --rm --no-deps app php artisan migrate --force

echo "==> Caching config/routes/views"
docker compose run --rm --no-deps app php artisan optimize

echo "==> Rolling the application"
docker compose up -d --remove-orphans app web scheduler
# Workers finish their current job before exiting (stop_grace_period 60s).
docker compose up -d --force-recreate worker
docker compose exec -T app php artisan queue:restart >/dev/null || true

echo "==> Health check"
PORT="$(grep -E '^STASH_HTTP_PORT=' .env | cut -d= -f2 || true)"
for i in $(seq 1 30); do
  if curl -fsS "http://127.0.0.1:${PORT:-8080}/up" >/dev/null; then
    echo "Stash is up (image tag $TAG)."
    docker compose exec -T app php artisan stash:storage || true
    exit 0
  fi
  sleep 2
done
echo "Health check failed — inspect: docker compose logs --tail=200 app web worker" >&2
exit 1
