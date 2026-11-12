#!/usr/bin/env bash
# Consistent backup of the PostgreSQL database + private files (Docker Compose).
#
#   scripts/backup.sh               # full: database + files
#   scripts/backup.sh --db-only     # database only (used before deployments)
#
# Consistency: the worker and scheduler are paused while the dump and the file
# archive are taken, so no queued job deletes or rewrites files in between.
# Uploads that land during the window can only create *extra* files (harmless
# orphans), never rows that point at missing files.
#
# Output: $BACKUP_DIR/stash-<timestamp>/{database.dump,files.tar.gz,SHA256SUMS}
# If BACKUP_GPG_RECIPIENT is set, the directory is packed and encrypted with gpg.
# Copy the result OFF the server (restic/rclone/borg) — a backup on the same
# VPS is not disaster recovery. See docs/BACKUP_RESTORE.md.
set -euo pipefail
cd "$(dirname "$0")/.."

MODE="${1:-full}"
STAMP="$(date -u +%Y%m%dT%H%M%SZ)"
BACKUP_DIR="${BACKUP_DIR:-./backups}"
OUT="$BACKUP_DIR/stash-$STAMP"
mkdir -p "$OUT"
chmod 700 "$BACKUP_DIR" "$OUT"

DB_USER="$(grep -E '^DB_USERNAME=' .env | cut -d= -f2)"
DB_NAME="$(grep -E '^DB_DATABASE=' .env | cut -d= -f2)"

resume() { docker compose start worker scheduler >/dev/null 2>&1 || true; }
trap resume EXIT

echo "==> Pausing worker + scheduler"
docker compose stop worker scheduler >/dev/null

echo "==> Dumping database"
docker compose exec -T postgres pg_dump -U "$DB_USER" -d "$DB_NAME" --format=custom --no-owner --no-privileges > "$OUT/database.dump"

if [ "$MODE" != "--db-only" ]; then
  echo "==> Archiving private files"
  docker compose run --rm --no-deps -T --entrypoint sh app -c 'tar -C /var/www/html/storage/app/private -czf - .' > "$OUT/files.tar.gz"
fi

resume
trap - EXIT

( cd "$OUT" && sha256sum ./* > SHA256SUMS )

if [ -n "${BACKUP_GPG_RECIPIENT:-}" ]; then
  tar -C "$BACKUP_DIR" -cf - "stash-$STAMP" | gpg --encrypt --recipient "$BACKUP_GPG_RECIPIENT" -o "$OUT.tar.gpg"
  rm -rf "$OUT"
  echo "Encrypted backup: $OUT.tar.gpg"
else
  echo "Backup written to $OUT (unencrypted — set BACKUP_GPG_RECIPIENT or use restic)."
fi

# Local retention (keep the newest 14). Off-site retention is configured in your backup tool.
ls -1dt "$BACKUP_DIR"/stash-* 2>/dev/null | tail -n +15 | xargs -r rm -rf
