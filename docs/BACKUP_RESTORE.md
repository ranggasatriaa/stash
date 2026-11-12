# Backup and restore

Stash state lives in two places that must be backed up **together**:

1. **PostgreSQL** (`pgdata` volume) — all records, tags, projects, notes, summaries.
2. **Private files** (`stash-files` volume → `storage/app/private`) — image
   originals, generated previews, pending exports/imports.

Redis holds only queues/cache and is not backed up. Backups are independent of the
JSON/ZIP export feature (exports are user-facing snapshots, not disaster recovery).

## Consistency

`scripts/backup.sh` pauses the `worker` and `scheduler` services, dumps the
database (`pg_dump --format=custom`), archives the files volume, then resumes them.
With workers paused nothing deletes or rewrites files between the two steps, so
every database row's file is in the archive. Web uploads during the window can only
add files that have no row yet (harmless orphans). The app keeps serving requests.

## Running a backup

```bash
scripts/backup.sh                 # database + files → ./backups/stash-<UTC timestamp>/
scripts/backup.sh --db-only       # database only (deploy.sh runs this automatically)
BACKUP_GPG_RECIPIENT=you@example.com scripts/backup.sh   # encrypted .tar.gpg
```

Each backup directory has `database.dump`, `files.tar.gz` and `SHA256SUMS`. The
script keeps the newest 14 local copies.

## Off-server, encrypted storage (required)

A backup on the same VPS is **not** disaster recovery (disk failure, provider
incident or compromise loses both). Ship backups off the server, encrypted, e.g.
with restic to S3-compatible storage or another machine:

```bash
# once
export RESTIC_REPOSITORY=s3:https://<endpoint>/<bucket>/stash
export RESTIC_PASSWORD_FILE=/root/.restic-pass        # keep a copy offline
restic init

# nightly (cron/systemd timer), after scripts/backup.sh
restic backup /opt/stash/backups --tag stash
restic forget --tag stash --keep-daily 7 --keep-weekly 4 --keep-monthly 12 --prune
```

Suggested schedule: daily full backup at a quiet hour, retention 7 daily / 4 weekly
/ 12 monthly off-site, 14 local. Monitor that the job ran (cron mail or a health-check
ping).

## Restore

Restore into a **separate** database first and inspect it:

```bash
scripts/restore.sh ./backups/stash-20261112T020000Z --target-db stash_restore
# prints resource/file/user counts; files are extracted to ./restore-check/files
```

Replace the live system (asks you to type the database name):

```bash
scripts/restore.sh ./backups/stash-20261112T020000Z --replace-live
```

This stops the app, recreates the database from the dump, replaces the files
volume, runs migrations (for backups taken before a newer release), restarts
services and prints `php artisan stash:storage`.

## Test your restores

Do a restore drill at least quarterly into a disposable host or `--target-db`, then
check: login works, counts match, a few images open, an export downloads.

## Verification status

The backup/restore scripts were written for the Docker Compose deployment and
syntax-checked (`bash -n`), but **were not executed in this environment because
Docker is not installed on the development machine**. The equivalent PostgreSQL
operations were not exercised against the Compose stack. Run a drill with disposable
data before relying on them:

```bash
docker compose up -d && docker compose run --rm app php artisan migrate --force
docker compose exec app php artisan stash:create-owner
# add a few resources and an image in the UI, then:
scripts/backup.sh
scripts/restore.sh ./backups/<newest> --target-db stash_restore
```
