# Decisions

Significant implementation choices for Stash, with the reasoning. Dates are
when the decision was made (2026-11-12 unless noted).

## Platform and versions

| Component             | Selected                                                                                           | Why                                                                                                                                                                                                                                                                                 |
| --------------------- | -------------------------------------------------------------------------------------------------- | ----------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| PHP                   | **8.3** (local 8.3.30; Docker `php:8.3-fpm-bookworm`)                                              | Laravel 13 requires ≥ 8.3; same minor locally and in production so the verified runtime is the deployed one. Security support until 2027-12. Upgrade path: bump `PHP_VERSION` build arg after running the test suite on 8.4.                                                        |
| Laravel               | **13.42** via the official `laravel/react-starter-kit` (dev-main, Laravel 13 / Inertia 3)          | Spec asks for the official React starter kit; the tagged 1.x kit is still Laravel 12.                                                                                                                                                                                               |
| Inertia               | `inertiajs/inertia-laravel` 3, `@inertiajs/react` 3                                                | Starter kit baseline; flash data, async visits, layout props.                                                                                                                                                                                                                       |
| React / TS / Tailwind | React 19.3, TypeScript 5.9, Tailwind 4.3, shadcn/ui (Radix), lucide-react                          | Starter kit baseline.                                                                                                                                                                                                                                                               |
| Build                 | Vite+ (`vite-plus` 1.4, Rolldown) as shipped by the starter kit, Node 24                           | Kept the starter's toolchain to stay compatible.                                                                                                                                                                                                                                    |
| PostgreSQL            | **18** (local 18; Docker `postgres:18-alpine`)                                                     | Canonical DB; `pg_trgm`, generated `tsvector` columns, builtin `C.UTF-8` locale provider (17+) for Unicode-correct `lower()`.                                                                                                                                                       |
| Redis                 | 8 in Docker (local dev used Laragon's Redis 5.0.14)                                                | Queue + cache only; never authoritative.                                                                                                                                                                                                                                            |
| Redis client          | **predis**                                                                                         | Pure PHP, identical on Windows dev and Linux prod; no PECL build.                                                                                                                                                                                                                   |
| Sanctum               | 4.4                                                                                                | Scoped personal access tokens for `/api/v1`.                                                                                                                                                                                                                                        |
| Images                | **Intervention Image 3.11 with the GD driver** (Imagick optional via `STASH_IMAGE_DRIVER=imagick`) | GD with JPEG/PNG/WebP was verified locally and is compiled into the Docker image; Imagick would be an unverified PECL build. GD re-encoding drops all EXIF/GPS data; orientation is applied via Intervention's auto-orientation (verified by test with an EXIF orientation=6 JPEG). |
| YAML                  | `symfony/yaml` 7.4                                                                                 | Proper serializer for Obsidian front matter.                                                                                                                                                                                                                                        |
| Tests                 | PHPUnit 12.5 (starter default) + Playwright 1.65                                                   | Spec allows PHPUnit; Playwright for browser flows.                                                                                                                                                                                                                                  |
| Fonts                 | `@fontsource-variable/sora` + `plus-jakarta-sans` (bundled)                                                       | Reference sheets use Nunito Sans; bundled locally (no Google/Bunny requests at runtime).                                                                                                                                                                                            |

## Product / domain

- **One `resources` table** for links, images and articles; sections (All, Bookmarks,
  Screenshots, Read later, Archive, Trash, Inbox) are query views. `NavigationCounts`
  uses exactly the predicates of `ResourceQuery::applySection()`; a test asserts that
  every sidebar count equals the size of its view.
- **IDs**: ULIDs (lower-case) for every owner-controlled table. `users.id` stays a
  bigint because Fortify/passkeys/sessions expect it; users are never exposed by id
  in URLs.
- **Archive vs Trash** are independent columns (`archived_at`, `deleted_at`), so
  restoring from Trash preserves the archive state. No automatic Trash purge.
- **Permanent delete only from Trash** (409 otherwise), requires explicit
  confirmation and, over the API, the separate `resources:delete` ability. AI run
  rows are kept for budget accounting but their result/input text is wiped.
- **Version column** (`version`) incremented in the same transaction as any
  relevant change (fields, tags, project, lifecycle, enrichment that changes
  content). Processing-status-only updates do not bump it, to avoid spurious
  conflicts while metadata loads. API `PATCH` _requires_ a version (428 otherwise);
  the web UI sends it and offers "load latest" / "save mine over it" on conflict.
- **Manual-field provenance**: `manual_fields` records `title`/`description` edited
  by the user (or provided on save). Enrichment checks it under a row lock at apply
  time, so edits made while a job runs always win. Fetched values are kept in
  `metadata.fetched`; the detail panel shows differing values and "replace from
  source" is an explicit action.
- **Provisional title** = host without `www.`; it is _not_ manual, so enrichment
  replaces it.
- **Read later only for articles**; non-article attempts are 422. Converting to an
  article initializes `unread`; converting away clears reading fields. Enforced by
  DB CHECK constraints and the service.
- **Tags** unique per owner on `normalized_name` = NFKC + whitespace collapse +
  leading `#` stripped + lower-case. Denormalized `tag_names` text feeds search and
  is recomputed by `ResourceTagger` on every association/rename/delete.
- **Projects**: one per resource; delete unassigns (with version bump) in the same
  transaction; assignment takes a `FOR SHARE` lock on the owned project row.
- **Inbox** is a built-in saved preset (active, no tags, no project). User saved
  views store normalized filter JSON (re-parsed through `ResourceFilters`).
- **Duplicates** warn, never block. URL normalization is conservative: lower-case
  scheme/host, drop default port, fragment and a fixed list of tracking params
  (`utm_*`, `fbclid`, `gclid`, …); other query params and path case are kept; the
  comparison hash ignores the scheme. Images compare SHA-256 of the original.
  Duplicate lookups are owner-scoped.
- **Bulk**: up to 500 ids; ids not owned are reported as `not_found` (same as
  missing) and never touched; ineligible items are reported with reasons. Undo is
  offered for operations with an exact inverse (archive, trash, restore, read
  state, read later, add/remove tags) — not for project assignment, where the
  previous per-item project would have to be restored.

## Search

PostgreSQL only (no external engine):

- Generated `search_vector` (`simple` config — mixed Indonesian/English):
  title + tags weight A, description B, URL words C, personal notes D.
- Generated lower-cased `search_document` (title, tags, description, URL) with a
  GIN trigram index for substring (`LIKE '%q%'`) and fuzzy (`<%` word similarity)
  matching. Notes are searchable through full-text only (documented).
- Queries of 1–2 characters use indexed prefix strategies only (`tsquery :*` and
  `lower(title) text_pattern_ops`).
- Relevance: exact title → exact tag → title prefix → tag prefix → `ts_rank` →
  trigram similarity → newest → id. Every sort ends with the id tie-breaker.
- User input never reaches tsquery syntax (tokens are letters/digits only) and LIKE
  wildcards are escaped; everything is parameterized.
- Tag filters use `EXISTS` subqueries (Any) / a counted subquery (All), so result
  rows are never duplicated by joins.
- The PostgreSQL connection runs in UTC; date filters convert the user's local
  day boundaries to UTC with an exclusive upper bound (inclusive end date).

## Security

- **SSRF-hardened fetcher** (`App\Stash\Fetch\SafeFetcher`) used for page metadata,
  preview images and article extraction: http(s) only, no credentials, ports
  80/443/8080/8443, blocked host names (`localhost`, `.internal`, single labels,
  numeric/hex hosts), _all_ resolved A/AAAA records must be public (IPv4 + IPv6
  incl. mapped/NAT64; 6to4/Teredo blocked), the connection is **pinned** to the
  validated IP with `CURLOPT_RESOLVE`, redirects followed manually and revalidated,
  TLS verification on, proxies ignored, no cookies, byte caps enforced while
  streaming via a short-write sink (also stops gzip bombs). Guzzle's cURL handler is
  forced because the PHP stream handler would ignore the DNS pin.
- **No remote images in the browser**: previews are downloaded through the fetcher,
  validated and re-encoded; favicons are only recorded (validated URL), not
  hot-linked. CSP `img-src 'self' data: blob:`.
- **CSP** with a per-request script nonce (Vite supported), `frame-ancestors 'none'`,
  `object-src 'none'`. Private files are served with `nosniff`, a sandboxing CSP and
  `Cross-Origin-Resource-Policy: same-origin`.
- **Uploads**: size → magic-byte MIME (finfo) → header type must match → per-side
  and total pixel limits — all before decoding. SVG/HTML/executables rejected.
  Storage keys are server-generated and validated against traversal.
- **Markdown notes** rendered with `react-markdown` (raw HTML skipped, unsafe URLs
  dropped, images turned into links). Descriptions are plain text.
- **Auth**: no public registration, no e-mail password reset or verification (no
  mail dependency); owner via `stash:create-owner` (hidden prompt),
  `stash:reset-password` for recovery. Fortify login throttling, optional 2FA and
  passkeys kept from the starter kit. Account self-deletion removed. Token
  management requires a recent password confirmation; plain-text tokens are shown
  once via flash data and never logged.
- **Sanctum is token-only** (`guard => []`) so `/api` never accepts session cookies.
- Password policy without the HIBP "uncompromised" check (no third-party calls).

## Jobs & reliability

- All jobs implement `ShouldQueueAfterCommit`; metadata and image jobs are
  `ShouldBeUnique` per resource. Transient network failures retry (3 tries,
  30s/120s backoff); permanent ones fail fast with a sanitized, actionable message.
- File deletion happens in `DeleteStoredFiles` after the rows are gone; missing
  files count as deleted so retries are idempotent.
- Variants are written before the DB swap; old variants are deleted afterwards.

## AI (optional DeepSeek)

- Verified against https://api-docs.deepseek.com on 2026-11-12: `POST /chat/completions`,
  models `deepseek-flash` and `deepseek-v4-pro`, JSON mode, non-thinking mode via
  `thinking: {type: disabled}`, usage fields, finish reasons, error codes. The model
  is **not hardcoded**: `DEEPSEEK_MODEL` must be set; `php artisan stash:ai-check`
  lists the provider's models.
- Two-step UX: preview shows the exact text to be sent and the token estimate; the
  confirm call sends that text. Metadata-only summaries need explicit acknowledgement.
- Budget: estimate = UTF-8 bytes/3 + prompt + max output; reserved while holding a
  row lock on the user, so concurrent requests cannot jointly exceed the monthly
  limit. Actual usage replaces the reservation; unknown usage keeps the estimate.
- Retries only for explicit "not processed" responses (429/503, insufficient
  capacity) with backoff, max 3 attempts. Timeouts and interrupted runs are never
  resent (ambiguous billing) and are charged at the reservation.
- Cache key: owner + SHA-256 of input + model + prompt version.
- `AI_PROVIDER=fake` gives a deterministic offline provider (used by E2E tests and
  demos; never calls a paid API).

## Import / export

- Export schema `stash.export` v1; ZIP adds `manifest.json` with SHA-256 for every
  entry and stores originals uncompressed. Exports expire (72 h default) and are
  pruned hourly.
- Import is preview-first; existing records are never modified. Same-owner id
  collisions: skip (default) or import a copy with a new id; ids used by another
  owner always get new ids. Tags merge by normalized name; projects reuse by id.
- ZIP reader never extracts by entry name: allow-listed names only, rejects
  traversal/absolute/drive paths, symlinks, encrypted entries, too many entries,
  oversized totals, and compression ratios > 200:1 for entries > 1 MiB; streams with
  hard caps and verifies manifest checksums.
- Obsidian snapshot: `Resources/{id}.md`, `Projects/{id}.md`, `attachments/{file_id}.{ext}`,
  YAML front matter via symfony/yaml, Markdown-escaped text, labelled AI summary
  callout. Obsidian `tags` replace characters like `/` with `-` (to avoid accidental
  nested tags); original names are kept in `stash_tags`.

## UI / design

- The user-supplied reference sheets (Foundations & Controls, Content & Feedback)
  override the spec's suggested tokens: ivory `#FFF8EF`, ink `#191A1D`, orange
  `#FF7417`, yellow `#FFD83D`, muted `#62656D`, Nunito Sans, 2 px borders, hard
  offset shadows, 12/20 px radii. Accessibility adjustments: orange is used as a
  fill with ink text (6.4:1); orange _text_ uses `#C2410C` (≥ 4.9:1); focus ring
  `#E35D00` (≥ 3:1 on ivory, white and ink).
- **Master CSS** `resources/css/stash.css` is framework-agnostic and reusable on other
  sites (see `docs/MASTER_CSS.md`). shadcn/ui primitives were remapped onto it.
- Light theme only (the starter's appearance/dark-mode switch was removed) to keep
  the reference look and contrast verified.
- Card / list layouts; desktop detail = right side sheet, mobile = full-screen sheet;
  mobile bottom navigation with a "More" drawer.

## Local verification environment

- The machine's PostgreSQL 18 service is password-protected, so verification used a
  **disposable PostgreSQL 18 cluster** (same binaries, `initdb` into a temp dir, port
  55432, trust auth on 127.0.0.1 only, builtin `C.UTF-8` locale). This is a test
  fixture, not part of the product; production uses the single Compose `postgres`.
- Docker is not installed on the development machine, so Compose/Docker files were
  validated syntactically only (see IMPLEMENTATION_STATUS.md).
