#!/usr/bin/env bash
# Bare-metal deploy for a VPS running nginx + PHP-FPM + PostgreSQL + Redis.
# (scripts/deploy.sh is the Docker Compose variant; this one is Option B in
# docs/BRIEF-option-b-master-tags.md.)
#
#   APP_DIR=/var/www/stash scripts/deploy-server.sh live    v1.3.0
#   APP_DIR=/var/www/stash scripts/deploy-server.sh staging origin/master
#
# Non-destructive: never resets the database or touches storage/app/private.
# The whole body lives in main() so that checking out a ref that changes this
# very file cannot corrupt the run (bash parses the full function first).
set -euo pipefail

main() {
  local target="${1:?usage: deploy-server.sh <live|staging> <ref>}"
  local ref="${2:?usage: deploy-server.sh <live|staging> <ref>}"
  local app_dir="${APP_DIR:?APP_DIR must point at the checkout}"
  local php_fpm="${PHP_FPM_SERVICE:-php8.5-fpm}"
  local backups="${BACKUP_DIR:-$HOME/backups}"
  local composer_flags=(--no-interaction --no-progress --optimize-autoloader)

  case "$target" in
    live) composer_flags+=(--no-dev) ;;
    staging) ;;
    *) echo "target must be 'live' or 'staging'" >&2; exit 2 ;;
  esac

  umask 0002 # storage/ is shared with the www-data PHP-FPM pool via group write
  cd "$app_dir"

  # One deploy at a time.
  exec 9>"$app_dir/storage/.deploy.lock"
  flock -n 9 || { echo "another deploy is running" >&2; exit 1; }

  [ -f .env ] || { echo ".env missing in $app_dir" >&2; exit 1; }
  grep -q '^APP_DEBUG=false' .env || { echo "APP_DEBUG must be false" >&2; exit 1; }

  trap 'echo; echo "!! Deploy of $ref FAILED. The app is left in maintenance mode on purpose." >&2; echo "   Fix the cause and re-run, or redeploy the previous tag. Bring it up with: php artisan up" >&2' ERR

  echo "==> Fetching $ref"
  git fetch --tags --force origin '+refs/heads/master:refs/remotes/origin/master'
  git rev-parse --verify --quiet "$ref^{commit}" >/dev/null || { echo "unknown ref: $ref" >&2; exit 1; }

  # Pre-deploy database dump (kept: last 10).
  if grep -q '^DB_CONNECTION=pgsql' .env && [ -f vendor/autoload.php ]; then
    echo "==> Database backup"
    mkdir -p "$backups"
    envval() { grep -E "^$1=" .env | tail -1 | cut -d= -f2- | sed -E "s/^(['\"])(.*)\1\$/\2/"; }
    PGPASSWORD="$(envval DB_PASSWORD)" pg_dump \
      -h "$(envval DB_HOST)" -p "$(envval DB_PORT)" -U "$(envval DB_USERNAME)" \
      --format=custom "$(envval DB_DATABASE)" > "$backups/pre-deploy-$(date +%Y%m%d-%H%M%S).dump"
    # shellcheck disable=SC2012
    ls -1t "$backups"/pre-deploy-*.dump 2>/dev/null | tail -n +11 | xargs -r rm -f
  fi

  echo "==> Maintenance mode"
  [ -f artisan ] && php artisan down --retry=60 || true

  echo "==> Checking out $ref"
  git checkout --force --detach "$ref" 2>/dev/null || git checkout --force "$ref"

  echo "==> composer install (${composer_flags[*]})"
  composer install "${composer_flags[@]}"

  echo "==> Building assets"
  npm ci --no-audit --no-fund
  npm run build

  echo "==> Migrations"
  php artisan migrate --force

  echo "==> Recording version"
  git describe --tags --always > storage/app/version.txt
  echo "    $(cat storage/app/version.txt)"

  echo "==> Caches"
  php artisan optimize:clear
  php artisan optimize

  echo "==> Restarting workers and PHP-FPM"
  php artisan queue:restart
  sudo -n systemctl reload "$php_fpm" || echo "    (could not reload $php_fpm; opcache will refresh on its own)"

  php artisan up
  trap - ERR
  echo "==> Deployed $(cat storage/app/version.txt) to $target"
}

main "$@"
exit
