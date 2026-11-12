# Implementation status

Last updated: 2026-11-12. Scope: the complete default release of
`STASH_BUILD.md` (milestones 1–6).

## Versions in use

Laravel 13.42.0 · PHP 8.3.30 · inertia-laravel 3.7 / @inertiajs/react 3.11 ·
React 19.3 · TypeScript 5.9 · Tailwind 4.3 · Vite+ 1.4 · Sanctum 4.4 ·
Fortify 1.42 · Intervention Image 3.11 (GD) · Guzzle 8.3 · predis 3.7 ·
symfony/yaml 7.4 · PHPUnit 12.5 · Larastan 3.15 · Playwright 1.65 ·
PostgreSQL 18 · Redis 8 (Docker) / 5.0 (local verification). Lockfiles
(`composer.lock`, `package-lock.json`) are included in the repository (nothing has been committed yet).

## Milestones

| #   | Milestone                                                                                                                           | Status                                             |
| --- | ----------------------------------------------------------------------------------------------------------------------------------- | -------------------------------------------------- |
| 1   | Scaffold, versions, decisions, DB/auth/private storage/queue/Compose                                                                | Done                                               |
| 2   | Schema, policies, domain services, CRUD, images, safe URL metadata, core tests                                                      | Done                                               |
| 3   | Responsive library/detail UI, tags/projects, read later, search/filter/sort, archive/trash, bulk, duplicates, saved views, settings | Done                                               |
| 4   | API v1, scoped tokens, version checks, idempotency, activity events, OpenAPI, tests                                                 | Done                                               |
| 5   | JSON/ZIP import-export, Obsidian snapshot, optional DeepSeek summaries + budget                                                     | Done                                               |
| 6   | Verification, responsive inspection, fixes, operational docs                                                                        | Done (Docker-dependent checks blocked — see below) |

## What is implemented

- **Auth**: owner via `stash:create-owner` (hidden prompt; `--password-from-env`
  for automation), no registration / e-mail reset / e-mail verification routes,
  logout, profile and password change, login throttling (5/min), optional 2FA and
  passkeys (starter kit), `stash:reset-password`, scoped token UI (create with
  abilities + expiry, show once, revoke; needs recent password confirmation).
- **Resources**: link/image/article with DB CHECK invariants; quick save with
  provisional host title; queued enrichment (title, description, OG title/
  description/image, site name, favicon URL; relative URLs resolved); status
  queued/processing/ready/failed with retry; manual-field provenance + explicit
  "replace from source"; image uploads (drag/drop, progress, preview, validation,
  thumbnail/preview WebP variants, EXIF orientation, metadata stripping, private
  streaming, download); notes in Markdown (sanitized rendering); read later /
  read / unread rules; archive, trash, restore, permanent delete with confirmation
  and file cleanup job; version increments.
- **Library UI**: dark sidebar (sections with counts, Inbox + saved views, projects,
  top tags, user/logout), toolbar (heading/count, debounced search with stale
  request cancellation, custom Filters panel, sort incl. Relevance, card/list
  layout), active-filter chips + Clear filters, pagination with per-page choice,
  card and table views, detail side panel (desktop) / full screen (mobile),
  bulk bar (tags, project, read/unread, archive, trash, restore, permanent delete)
  with skip reporting and Undo toasts, duplicate warnings with "Save anyway",
  drag-and-drop anywhere to upload, `/` focuses search, empty/no-results/error/
  loading states, mobile top bar + bottom navigation + "More" drawer.
- **Tags & projects** management pages with active/total counts, rename/recolor,
  delete with consequence confirmation.
- **Search**: PostgreSQL FTS (`simple`) + pg_trgm, relevance tiers, short-query
  prefix strategy, AND filter groups, tag Any/All, project incl. Unassigned,
  reading filter (articles only), timezone-correct inclusive date range, stable
  sorts, bounded pages; state persisted in the query string.
- **API v1** (`/api/v1`): all routes from the spec plus `/me`, duplicates, files,
  tags/projects item routes, exports/imports; abilities + owner policies;
  consistent error envelope with request ids; 401/403/404/409/410/422/428/429/503;
  rate limits; `Idempotency-Key` (JSON + multipart fingerprinting, 24 h expiry);
  `version` / `If-Match` preconditions; activity events with actor + request id.
  Contract: `docs/openapi.yaml` (+ `x-agent-tools`), guide: `docs/API.md`.
- **Import/export**: JSON, ZIP (+ manifest checksums) and Obsidian snapshot
  exports (queued, private, expiring, pruned hourly); preview-first import with
  collision strategy, duplicate warnings, partial error report, attachments
  re-validated and re-processed; hardened ZIP reader.
- **AI (optional)**: DeepSeek provider (verified API shape), fake offline provider,
  preview of exact input, metadata-only acknowledgement, monthly token budget with
  locked reservations, cache, bounded retries for explicit "not processed"
  errors only, timeout/interruption never resent, structured output validation,
  `stash:ai-check`, settings page with usage in tokens.
- **Security**: SSRF-hardened fetcher with DNS pinning; CSP with script nonces;
  private file delivery headers; upload validation before decoding; Markdown
  sanitization; token-only Sanctum; secure-cookie/HTTPS guidance; trusted proxies
  via `TRUSTED_PROXIES`; destructive DB commands blocked in production.
- **Ops**: Dockerfile (app + web targets, Node only in build stage), Nginx config,
  `compose.yaml` (app, web, worker, scheduler, postgres, redis; health checks;
  persistent volumes; DB/Redis internal), `compose.dev.yaml`, dev bootstrap
  (`.sh` + `.ps1`), non-destructive `deploy.sh`, `backup.sh`, `restore.sh`,
  `stash:storage` (usage + failed jobs), scheduler tasks.
- **Design**: master CSS `resources/css/stash.css` (framework-agnostic, documented
  in `docs/MASTER_CSS.md`, standalone style guide `docs/styleguide/index.html`),
  shadcn primitives remapped onto it, brand icons (SVG/ICO/Apple touch).

## Verification actually performed

Environment: Windows 11, PHP 8.3.30 (CLI + built-in server), Node 24.18,
PostgreSQL 18 (disposable local cluster, builtin C.UTF-8 locale), Laragon Redis
5.0.14, Playwright Chromium.

| Check                                                                                                                                                                                                                                                  | Result                                   |
| ------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------ | ---------------------------------------- |
| `php artisan test` (PHPUnit, PostgreSQL `stash_test`)                                                                                                                                                                                                  | **139 passed, 790 assertions**           |
| `vendor/bin/phpstan analyse` (larastan level 7)                                                                                                                                                                                                        | **0 errors**                             |
| `vendor/bin/pint --test`                                                                                                                                                                                                                               | **pass**                                 |
| `npm run types:check` (`tsc --noEmit`)                                                                                                                                                                                                                 | **pass**                                 |
| `npx vp check` (lint + format)                                                                                                                                                                                                                         | **pass** (63 files linted, 85 formatted) |
| `npm run build` (production assets)                                                                                                                                                                                                                    | **pass**                                 |
| `npx playwright test` (desktop 1440 px + mobile 390/320 px, real PHP server, PostgreSQL `stash_e2e`, Redis queue with a real `queue:work` process)                                                                                                     | **8 passed**                             |
| Real queue worker smoke test (Redis, dev DB): saved `https://example.com/`; first two fetches timed out on the slow local connection, the worker retried with 30 s/120 s backoff and the third attempt enriched it (title "Example Domain", version 2) | **pass**                                 |
| Manual UI inspection in a browser at 1440, 390 and 320 px (library, detail panel, settings/data, login) and Playwright overflow checks on library, screenshots, projects, tags, settings/data, settings/preferences and the detail view                | **no horizontal overflow**               |
| Standalone style guide rendered with only `stash.css` (1280 px and 360 px)                                                                                                                                                                             | **renders, no overflow**                 |
| `compose.yaml`, `compose.dev.yaml`, `docs/openapi.yaml` YAML parse; `bash -n` on all scripts                                                                                                                                                           | **pass**                                 |

Required test areas (spec §14) → files:

1. Auth, isolation, files, tokens, registration — `tests/Feature/Stash/AuthIsolationTest.php`
2. Quick save, queued metadata, failures, manual-edit protection — `MetadataPipelineTest.php`
3. SSRF incl. IPv6, redirects, DNS pinning, unsafe preview URLs, real cURL option validation — `SsrfTest.php`
4. Image processing, spoofing, limits, orientation/stripping, private delivery — `ImageUploadTest.php`
5. Tags/projects/read status survive edits; deletes keep resources; counts — `TagsProjectsTest.php`
6. Search fields, relevance, AND/Any/All, timezone dates, stable paging, exclusions — `SearchFilterTest.php`
7. Read/unread, archive/trash/restore/permanent delete, file cleanup retries — `LifecycleTest.php`
8. Bulk authorization, duplicates, idempotency replay/mismatch, stale updates — `BulkIdempotencyTest.php`
9. JSON/ZIP round trip, malicious archives, Obsidian YAML/links — `TransferTest.php`
10. AI disabled UX, mocked success, cache, budget, invalid output, timeout, retries — `AiSummaryTest.php`
11. Browser flows, keyboard dialog, mobile — `tests/e2e/stash.spec.ts`; API contract — `ApiContractTest.php`

Bugs found by verification and fixed: Guzzle 8 rejects raw `CURLOPT_PROTOCOLS`
(fetcher now uses Guzzle's `protocols` option; covered by a test that builds a
real cURL handle); a synchronous-queue metadata failure could break the save
request (now never rethrown under the sync driver); TLS errors are classified
separately; programmatically opened dialogs now return focus to their opener;
Guzzle 8 moved `getResponse()` to `ResponseException` (oversized-body path).

## Not verified (blocked) — please run these

| Item                                                                            | Why                                                                | How to verify                                                                                                                               |
| ------------------------------------------------------------------------------- | ------------------------------------------------------------------ | ------------------------------------------------------------------------------------------------------------------------------------------- |
| Docker image build, `docker compose config`, Compose start-up and health checks | Docker is not installed on the build machine                       | `docker compose config && docker compose build && docker compose up -d` on the VPS or any Docker host, then `curl http://127.0.0.1:8080/up` |
| Backup/restore scripts against the Compose stack                                | needs Docker                                                       | follow "Verification status" in `docs/BACKUP_RESTORE.md` with disposable data                                                               |
| Imagick driver                                                                  | extension not available locally; GD is the default and is verified | set `STASH_IMAGE_DRIVER=imagick` only after installing the extension and running the image tests                                            |
| Real DeepSeek API call                                                          | no key provided (by design)                                        | set the `DEEPSEEK_*` variables, run `php artisan stash:ai-check`, then summarize one article                                                |
| Redis 8 / PostgreSQL 18 inside Docker                                           | verified natively with PostgreSQL 18 and Redis 5 only              | covered by the Compose staging run above                                                                                                    |
| TLS reverse proxy and `TRUSTED_PROXIES`                                         | environment-specific                                               | after deployment, check that cookies are `Secure` and generated URLs are `https`                                                            |

## Known limitations

- Metadata fetching needs server-side HTML; JavaScript-only or bot-blocking pages
  fail gracefully and must be edited manually (no headless browser by design).
- Favicons are recorded but not displayed (no third-party image loading).
- Undo is not offered for bulk project assignment (no exact inverse).
- The fetch timeout (10 s) can be tight on slow links; tune `STASH_FETCH_TIMEOUT`.
- Light theme only.
- Single owner in practice; multiple users are supported by the data model and
  authorization (`--allow-additional`) but there is no user administration UI.
- Browser-bookmark HTML import (optional) is not implemented.
- Offline support, sync, tag merge and AI tagging are future work (`docs/FUTURE_SYNC.md`).

## Remaining items

1. Run the Docker/Compose staging deployment and a backup/restore drill.
2. Optional: browser-bookmark HTML import; Imagick driver verification.
