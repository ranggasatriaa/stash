# Deploying Stash to a VPS

Target: one Linux VPS running Docker Engine + Compose v2. Services:
`app` (PHP-FPM), `web` (Nginx), `worker` (queue), `scheduler`, `postgres`, `redis`.
PostgreSQL and Redis are only on the internal Docker network. `web` binds to
`127.0.0.1:8080` and a TLS reverse proxy on the host publishes HTTPS.

**Capacity starting point:** 2 vCPU / 4 GB RAM, disk sized for your images
(originals + ~10–25 % for previews) plus backups. This is a starting estimate,
not a performance guarantee.

> Verification note: Docker was not available on the machine where Stash was
> built, so the images and Compose stack were **not** built or started there. The
> application, migrations, queue worker and tests were verified natively against
> PostgreSQL 18 + Redis. Do a staging run first (below).

## 1. Prepare the server

```bash
# as root (Debian/Ubuntu)
apt-get update && apt-get install -y ca-certificates curl git ufw
curl -fsSL https://get.docker.com | sh           # Docker Engine + compose plugin
adduser --disabled-password stash && usermod -aG docker stash
ufw allow OpenSSH && ufw allow 80/tcp && ufw allow 443/tcp && ufw enable
```

Do not open 5432/6379/8080 publicly.

## 2. Get the code and configure

```bash
sudo -iu stash
git clone <your-repo-url> /opt/stash && cd /opt/stash
cp .env.example .env && chmod 600 .env
```

Edit `.env` (all variables are documented in `.env.example`):

| Variable                                                       | Production value                                                                                                                                         |
| -------------------------------------------------------------- | -------------------------------------------------------------------------------------------------------------------------------------------------------- |
| `APP_ENV` / `APP_DEBUG`                                        | `production` / `false`                                                                                                                                   |
| `APP_URL`                                                      | `https://stash.example.com`                                                                                                                              |
| `APP_KEY`                                                      | output of `docker compose run --rm --no-deps app php artisan key:generate --show` (after the first build), or `echo "base64:$(openssl rand -base64 32)"` |
| `DB_HOST`                                                      | ignored in Compose (forced to `postgres`)                                                                                                                |
| `DB_DATABASE` / `DB_USERNAME` / `DB_PASSWORD`                  | strong password (`openssl rand -hex 24`); Compose creates the DB on first start                                                                          |
| `REDIS_PASSWORD`                                               | strong password; `REDIS_CLIENT=predis`                                                                                                                   |
| `SESSION_DRIVER` / `SESSION_SECURE_COOKIE` / `SESSION_ENCRYPT` | `database` / `true` / `true`                                                                                                                             |
| `QUEUE_CONNECTION` / `CACHE_STORE`                             | `redis` / `redis`                                                                                                                                        |
| `LOG_LEVEL`                                                    | `warning`                                                                                                                                                |
| `STASH_DISPLAY_TIMEZONE`                                       | e.g. `Asia/Jakarta` (default for new users)                                                                                                              |
| `STASH_UPLOAD_MAX_BYTES` / `STASH_UPLOAD_MAX_PIXELS`           | defaults 10 MiB / 40 MP                                                                                                                                  |
| `STASH_FETCH_*`                                                | metadata fetch limits (defaults are conservative)                                                                                                        |
| `TRUSTED_PROXIES`                                              | IP of the reverse proxy as seen from the container network, e.g. `172.16.0.0/12` for a host proxy, or `*` only if nothing else can reach `web`           |
| `STASH_HTTP_PORT`                                              | local port for `web` (default 8080)                                                                                                                      |
| `AI_ENABLED`, `DEEPSEEK_*`, `AI_MONTHLY_TOKEN_LIMIT`           | optional, see §6                                                                                                                                         |

Never commit `.env`. Secrets live only there (or in your secret manager).

## 3. First deployment

```bash
docker compose build
docker compose up -d postgres redis
docker compose run --rm app php artisan migrate --force
docker compose run --rm app php artisan stash:create-owner      # hidden password prompt
docker compose up -d
curl -fsS http://127.0.0.1:8080/up
```

## 4. TLS reverse proxy

Any proxy works. Caddy (automatic Let's Encrypt) on the host:

```caddyfile
stash.example.com {
    encode gzip
    reverse_proxy 127.0.0.1:8080
    request_body {
        max_size 520MB
    }
}
```

or Nginx on the host:

```nginx
server {
    listen 443 ssl http2;
    server_name stash.example.com;
    ssl_certificate     /etc/letsencrypt/live/stash.example.com/fullchain.pem;
    ssl_certificate_key /etc/letsencrypt/live/stash.example.com/privkey.pem;
    client_max_body_size 520m;
    location / {
        proxy_pass http://127.0.0.1:8080;
        proxy_set_header Host $host;
        proxy_set_header X-Forwarded-For $proxy_add_x_forwarded_for;
        proxy_set_header X-Forwarded-Proto https;
        proxy_set_header X-Forwarded-Host $host;
    }
}
server { listen 80; server_name stash.example.com; return 301 https://$host$request_uri; }
```

Set `TRUSTED_PROXIES` so Laravel honours `X-Forwarded-Proto` (secure cookies,
HTTPS URLs). The app adds HSTS when the request is secure.

## 5. Subsequent deployments

```bash
cd /opt/stash && git pull --ff-only && scripts/deploy.sh
```

`deploy.sh` is non-destructive: it refuses to run unless `APP_ENV=production` and
`APP_DEBUG=false`, takes a database dump, builds tagged images, runs
`migrate --force` **once** in a one-off container, caches config/routes/views,
recreates `app/web/scheduler`, gracefully recreates the `worker` (it finishes the
current job, `stop_grace_period: 60s`) and signals `queue:restart`, then waits for
`/up`. It never runs `migrate:fresh`, `db:wipe` or seeders (those are also
blocked in production by `DB::prohibitDestructiveCommands`).

**Uploads survive rebuilds**: files live in the named volume `stash-files`
(mounted at `storage/app/private`), the database in `pgdata`. Images can be rebuilt
and containers recreated freely. `docker compose down -v` would delete volumes —
never use `-v` in production.

Rollback: `git checkout <previous tag>` and `STASH_IMAGE_TAG=<tag> docker compose up -d`.
Migrations are additive; if a migration must be reverted, restore the pre-deploy
dump (`docs/BACKUP_RESTORE.md`).

## 6. Optional DeepSeek summaries

```bash
# .env
AI_ENABLED=true
DEEPSEEK_API_KEY=sk-...            # server-side only
DEEPSEEK_BASE_URL=https://api.deepseek.com
DEEPSEEK_MODEL=deepseek-flash      # verify current ids at https://api-docs.deepseek.com
AI_MONTHLY_TOKEN_LIMIT=200000
```

```bash
docker compose up -d app worker
docker compose exec app php artisan stash:ai-check   # lists provider models; spends no tokens
```

Without these values the AI controls show a "not enabled" explanation and every
other feature works unchanged.

## 7. Operations

| Task                                | Command                                                                                                           |
| ----------------------------------- | ----------------------------------------------------------------------------------------------------------------- |
| Logs                                | `docker compose logs -f --tail=200 app worker web`                                                                |
| Storage usage + failed jobs         | `docker compose exec app php artisan stash:storage`                                                               |
| Failed jobs                         | `docker compose exec app php artisan queue:failed` → `queue:retry <id>` / `queue:retry all` / `queue:forget <id>` |
| Stuck metadata/preview              | open the item → "Retry" (or `POST /api/v1/resources/{id}/refresh-metadata`)                                       |
| Restart workers after config change | `docker compose exec app php artisan queue:restart`                                                               |
| Reset owner password                | `docker compose exec app php artisan stash:reset-password you@example.com`                                        |
| Housekeeping                        | runs hourly via `scheduler` (`stash:prune`: expired exports/imports, idempotency keys)                            |
| Backups                             | `docs/BACKUP_RESTORE.md`                                                                                          |

**Failed-job troubleshooting:** `metadata` failures are expected for pages that block
bots or need JavaScript — the item stays usable and can be edited manually.
`image.process_failed` usually means a corrupt upload; the original is kept and
"Retry" re-runs processing. Repeated `DeleteStoredFiles` failures indicate a
permissions problem on the `stash-files` volume (`docker compose exec app ls -la storage/app/private`).

## 8. Hardening checklist

- `APP_DEBUG=false`, `APP_ENV=production`, HTTPS only, `SESSION_SECURE_COOKIE=true`.
- Only 22/80/443 open; `web` bound to 127.0.0.1; DB/Redis not published.
- Strong `DB_PASSWORD`/`REDIS_PASSWORD`; `.env` mode 600 owned by the deploy user.
- Containers run PHP as `www-data`; storage permissions `u=rwX,g=rX,o=`.
- Optional egress restriction: the metadata fetcher already blocks private
  networks; on the host you can additionally drop container egress to RFC1918 and
  link-local ranges with iptables (`DOCKER-USER` chain).
- Off-server encrypted backups configured and a restore drill done.
