#!/usr/bin/env bash
# Restore a backup made by scripts/backup.sh.
#
#   scripts/restore.sh ./backups/stash-20261112T020000Z --target-db stash_restore
#
# By default it restores into a NEW database (stash_restore) and a separate
# files directory so you can verify before switching. Restoring over the live
# database requires --replace-live and an interactive confirmation.
set -euo pipefail
cd "$(dirname "$0")/.."

SRC="${1:?Usage: scripts/restore.sh <backup-dir> [--target-db NAME] [--replace-live]}"
shift
TARGET_DB="stash_restore"
REPLACE=0
while [ $# -gt 0 ]; do
  case "$1" in
    --target-db) TARGET_DB="$2"; shift 2 ;;
    --replace-live) REPLACE=1; shift ;;
    *) echo "Unknown option $1" >&2; exit 1 ;;
  esac
done

( cd "$SRC" && sha256sum -c SHA256SUMS )

DB_USER="$(grep -E '^DB_USERNAME=' .env | cut -d= -f2)"
LIVE_DB="$(grep -E '^DB_DATABASE=' .env | cut -d= -f2)"

if [ "$REPLACE" = 1 ]; then
  read -r -p "This REPLACES the live database '$LIVE_DB' and files. Type the database name to confirm: " answer
  [ "$answer" = "$LIVE_DB" ] || { echo "Aborted."; exit 1; }
  TARGET_DB="$LIVE_DB"
  docker compose stop app worker scheduler web
fi

echo "==> Restoring database into $TARGET_DB"
docker compose exec -T postgres dropdb -U "$DB_USER" --if-exists "$TARGET_DB"
docker compose exec -T postgres createdb -U "$DB_USER" "$TARGET_DB"
docker compose exec -T postgres pg_restore -U "$DB_USER" -d "$TARGET_DB" --no-owner --no-privileges < "$SRC/database.dump"

if [ -f "$SRC/files.tar.gz" ]; then
  if [ "$REPLACE" = 1 ]; then
    echo "==> Restoring files into the live volume"
    docker compose run --rm --no-deps -T --entrypoint sh app -c 'find /var/www/html/storage/app/private -mindepth 1 -delete && tar -C /var/www/html/storage/app/private -xzf -' < "$SRC/files.tar.gz"
  else
    mkdir -p "./restore-check/files"
    tar -C "./restore-check/files" -xzf "$SRC/files.tar.gz"
    echo "==> Files extracted to ./restore-check/files for inspection"
  fi
fi

echo "==> Verifying"
docker compose exec -T postgres psql -U "$DB_USER" -d "$TARGET_DB" -At -c \
  "SELECT 'resources=' || count(*) FROM resources UNION ALL SELECT 'files=' || count(*) FROM resource_files UNION ALL SELECT 'users=' || count(*) FROM users;"

if [ "$REPLACE" = 1 ]; then
  docker compose run --rm --no-deps app php artisan migrate --force
  docker compose up -d app web worker scheduler
  docker compose exec -T app php artisan stash:storage
fi
echo "Restore finished."
