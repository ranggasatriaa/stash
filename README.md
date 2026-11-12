# Stash

**Good finds, kept close.** A private, responsive personal resource library:
**Save → Tag → Organize → Search → Revisit**. Links, screenshots, read-later
articles and project resources are one kind of thing (a _resource_); Bookmarks,
Screenshots, Read later, Projects, Tags, Archive and Trash are overlapping views.

Laravel 13 · Inertia 3 · React 19 + TypeScript · Tailwind 4 + shadcn/ui · PostgreSQL 18
(full-text + pg_trgm) · Redis queues · Sanctum API · Docker Compose for a VPS.

## Features

- Save URLs instantly (metadata fetched in the background through an SSRF-hardened
  fetcher) or upload JPEG/PNG/WebP screenshots (validated, previews generated, EXIF
  orientation applied, location metadata stripped, originals private).
- Multiple tags, one optional project, read later + read/unread for articles,
  personal Markdown notes, archive and trash (independent), permanent delete.
- Search across title, description, URL, tags and notes with relevance ranking,
  prefix and fuzzy matching; filters by type, tags (Any/All), project (incl.
  Unassigned), reading status and created-date range in your timezone; sort, pages,
  saved views and an Inbox preset. Everything is kept in the URL.
- Bulk actions with skip reports and Undo, duplicate warnings (normalized URL and
  image SHA-256), manual edits never overwritten by refreshes.
- Versioned API (`/api/v1`) with scoped tokens, version preconditions, idempotency
  keys, consistent errors, OpenAPI contract and agent tool contracts.
- JSON/ZIP export + round-trip import with preview, and a one-way Obsidian
  Markdown snapshot.
- Optional, manually triggered DeepSeek article summaries with a visible preview of
  what is sent and a per-user monthly token budget. Fully usable without AI.
- **Master CSS** (`resources/css/stash.css`): a standalone design system you can
  reuse on other sites — see [docs/MASTER_CSS.md](docs/MASTER_CSS.md) and the
  [style guide](docs/styleguide/index.html).

## Requirements

PHP 8.3+ (pdo_pgsql, intl, gd with JPEG/PNG/WebP, exif, zip, fileinfo, mbstring),
Composer 2, Node 24+, PostgreSQL 17+ with `pg_trgm` (18 recommended, UTF-8 with a
Unicode-aware locale), Redis 6+.

## Start locally

```bash
cp .env.example .env                 # set DB_* (PostgreSQL) and REDIS_* — SQLite is not supported
scripts/dev-bootstrap.sh             # or scripts/dev-bootstrap.ps1 on Windows
php artisan stash:create-owner       # name, e-mail, hidden password prompt
php artisan stash:demo-seed          # optional demo fixtures (refused in production)
```

Run the app and a queue worker (two terminals):

```bash
php -d upload_max_filesize=12M -d post_max_size=14M -S 127.0.0.1:8000 -t public scripts/dev-router.php
```

```bash
php artisan queue:work redis --queue=default,ai
```

Open http://127.0.0.1:8000 and sign in. For hot reload run `npm run dev` as well.
(`php artisan serve` works too, but cannot raise PHP's upload limit above 2 MB.)

Need PostgreSQL/Redis quickly? `docker compose -f compose.yaml -f compose.dev.yaml up -d postgres redis`
exposes them on 127.0.0.1 (set `DB_PASSWORD`/`REDIS_PASSWORD` in `.env` first).

## Optional: DeepSeek summaries

Set on the server only (never in the browser or the repo):

```bash
AI_ENABLED=true
DEEPSEEK_API_KEY=sk-...
DEEPSEEK_BASE_URL=https://api.deepseek.com
DEEPSEEK_MODEL=deepseek-flash        # check current ids at https://api-docs.deepseek.com
AI_MONTHLY_TOKEN_LIMIT=200000
```

Then `php artisan stash:ai-check` (lists provider models, spends no tokens) and restart
the worker. Without these values the summary controls explain how to enable them.

## Deploy to a VPS

Docker Compose runs app (PHP-FPM), web (Nginx), worker, scheduler, PostgreSQL and
Redis; DB/Redis are not published. First deployment:

```bash
cp .env.example .env    # production values, see docs/DEPLOYMENT.md
docker compose build && docker compose up -d postgres redis
docker compose run --rm app php artisan migrate --force
docker compose run --rm app php artisan stash:create-owner
docker compose up -d
```

Later: `git pull && scripts/deploy.sh` (non-destructive: backup, build, migrate once,
graceful worker restart, health check). Put a TLS proxy (Caddy/Nginx) in front.
Full runbook: [docs/DEPLOYMENT.md](docs/DEPLOYMENT.md). Backups:
[docs/BACKUP_RESTORE.md](docs/BACKUP_RESTORE.md).

## Tests and checks

```bash
php artisan test                 # PHPUnit on PostgreSQL (database stash_test, see phpunit.xml)
vendor/bin/phpstan analyse       # larastan level 7
vendor/bin/pint --test           # PHP style
npm run types:check              # TypeScript
npx vp check                     # lint + format (Vite+)
npm run build                    # production assets
npx playwright test              # browser flows; resets the disposable stash_e2e DB, needs Redis
```

Playwright needs `npx playwright install chromium` once, a `stash_e2e` PostgreSQL
database and a built frontend. Local test credentials live in
`tests/e2e/.env.e2e.example` (test-only values).

## Useful commands

| Command                                        | Purpose                                                       |
| ---------------------------------------------- | ------------------------------------------------------------- |
| `php artisan stash:create-owner`               | Create the owner (no public registration)                     |
| `php artisan stash:reset-password <email>`     | Reset a password from the server                              |
| `php artisan stash:demo-seed`                  | Development fixtures (refused in production)                  |
| `php artisan stash:ai-check`                   | Verify optional DeepSeek configuration                        |
| `php artisan stash:storage`                    | Storage usage per owner + failed jobs                         |
| `php artisan stash:prune`                      | Expired exports/imports + idempotency keys (scheduled hourly) |
| `php artisan queue:failed` / `queue:retry all` | Inspect / retry failed jobs                                   |

## Documentation

- [docs/API.md](docs/API.md) and [docs/openapi.yaml](docs/openapi.yaml) — API v1, abilities, examples, agent tools
- [docs/DEPLOYMENT.md](docs/DEPLOYMENT.md) — VPS runbook, environment, TLS, operations
- [docs/BACKUP_RESTORE.md](docs/BACKUP_RESTORE.md) — consistent backups, off-site storage, restore drill
- [docs/DECISIONS.md](docs/DECISIONS.md) — versions and design decisions
- [docs/FUTURE_SYNC.md](docs/FUTURE_SYNC.md) — offline / sync / Obsidian adapter design (future)
- [docs/MASTER_CSS.md](docs/MASTER_CSS.md) — reusable design system
- [docs/IMPLEMENTATION_STATUS.md](docs/IMPLEMENTATION_STATUS.md) — what is done, verified, and remaining
