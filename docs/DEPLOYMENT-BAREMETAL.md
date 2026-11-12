# Bare-metal deployment (Option B: master → staging, tag → live)

Implements `docs/BRIEF-option-b-master-tags.md` without Docker. The Docker Compose
path in `docs/DEPLOYMENT.md` still works; this is the alternative used on the
live VPS (Ubuntu 24.04: nginx, PHP 8.5-FPM, PostgreSQL 18, Redis, Node).

## Release flow

```
git push origin master            # staging only (skipped until STAGING_HOST is set)
git tag v1.3.0 && git push origin v1.3.0   # live
```

- The workflow refuses a tag whose commit is not on `master`.
- Rollback: Actions → *deploy* → *Run workflow* → enter an older tag (or re-run that tag's run).
- The sidebar footer shows `config('app.version')` (`storage/app/version.txt`).

## Server layout (live: 43.173.14.75)

| Thing | Where |
| --- | --- |
| Checkout (detached HEAD, nothing committed on the server) | `/var/www/stash` (`ubuntu:www-data`, setgid) |
| Config | `/var/www/stash/.env` (640, `ubuntu:www-data`) |
| Uploads | `storage/app/private/stash` (back this up) |
| nginx vhost | `/etc/nginx/sites-available/stash` |
| PHP settings | `/etc/php/8.5/fpm/conf.d/90-stash.ini` (opcache timestamps off: deploys reload FPM) |
| Queue worker | `systemctl status stash-worker` |
| Scheduler | `/etc/cron.d/stash` (`schedule:run` every minute) |
| Pre-deploy DB dumps | `~/backups/pre-deploy-*.dump` (last 10 kept) |
| Firewall | ufw: 22, 80, 443 only; Postgres/Redis bind to localhost |

`ubuntu` is in group `www-data` and PHP-FPM runs with `UMask=0002`, so both can
write `storage/` and `bootstrap/cache`.

## GitHub repository secrets

`SSH_PRIVATE_KEY`, `SSH_USER` (`ubuntu`), `LIVE_HOST`, `LIVE_PATH` (`/var/www/stash`),
and optionally `SSH_KNOWN_HOSTS` (pins the host key; otherwise `ssh-keyscan` is used),
`STAGING_HOST`, `STAGING_PATH`. Use a dedicated key pair for CI, not a personal key:

```bash
ssh-keygen -t ed25519 -N "" -C github-actions-stash -f ./ci_key
ssh-copy-id -i ./ci_key.pub ubuntu@43.173.14.75      # then paste ./ci_key into SSH_PRIVATE_KEY
ssh-keyscan -t ed25519 43.173.14.75                    # paste into SSH_KNOWN_HOSTS
```

Also: protect `master` (PR + passing `tests` workflow) and protect tags `v*` (Settings → Tags).
To make live wait for approval, add required reviewers to the GitHub Environment `live`.

## Manual deploy / recovery on the server

```bash
cd /var/www/stash
APP_DIR=/var/www/stash bash scripts/deploy-server.sh live v1.3.0
```

A failed deploy leaves the app in maintenance mode on purpose (a half-migrated
app should not serve traffic). Fix the cause and re-run, or redeploy the previous
tag; `php artisan up` brings it back.

Never run `migrate:fresh`, `db:wipe` or seeders on live.
