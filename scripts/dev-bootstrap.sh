#!/usr/bin/env bash
# Stash local development bootstrap (macOS / Linux / Git Bash on Windows).
# Safe to re-run: never drops data. Requires PHP 8.3+, Composer 2, Node 24+,
# PostgreSQL 17+ (pg_trgm available) and Redis reachable as configured in .env.
set -euo pipefail
cd "$(dirname "$0")/.."

need() { command -v "$1" >/dev/null 2>&1 || { echo "Missing dependency: $1" >&2; exit 1; }; }
need php; need composer; need node; need npm

php -r 'exit(version_compare(PHP_VERSION, "8.3.0", ">=") ? 0 : 1);' || { echo "PHP 8.3+ required" >&2; exit 1; }
for ext in pdo_pgsql intl gd exif zip mbstring fileinfo; do
  php -m | grep -qi "^${ext}$" || { echo "PHP extension missing: ${ext}" >&2; exit 1; }
done

if [ ! -f .env ]; then
  cp .env.example .env
  echo "Created .env from .env.example — edit DB_* and REDIS_* before continuing if needed."
fi

composer install --no-interaction
npm ci --no-audit --no-fund

grep -q '^APP_KEY=base64:' .env || php artisan key:generate

echo "Checking database connection…"
php artisan migrate --force   # additive only; never resets

php artisan wayfinder:generate --with-form >/dev/null
npm run build

cat <<'NEXT'

Stash is ready. Next steps:
  1. Create the owner (hidden password prompt):   php artisan stash:create-owner
  2. Optional demo data (local only):             php artisan stash:demo-seed
  3. Run the app:                                 php -d upload_max_filesize=12M -d post_max_size=14M -S 127.0.0.1:8000 -t public scripts/dev-router.php
     (or `npm run dev` in another terminal for hot reload + `php artisan serve`)
  4. Run a queue worker (metadata, images, exports, AI):
                                                  php artisan queue:work redis --queue=default,ai
  5. Tests:                                       php artisan test && npx playwright test
NEXT
