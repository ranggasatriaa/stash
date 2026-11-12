# Future offline support and sync (design only)

The first release is **online-first**. Built JS/CSS assets are cacheable, but that
is not offline support: nothing can be saved or edited without the server, and no
service worker or client database is shipped. PostgreSQL is the only source of
truth. This document describes how offline clients and a local agent could be
added later **without** changing that.

## Principles

1. **The API is the only sync path.** Clients (browser cache, desktop app, local
   agent) talk to `/api/v1` with scoped tokens. No direct database access, no
   bidirectional PostgreSQL replication, and never syncing a live database file
   through file-sync tools (Syncthing/Dropbox/iCloud).
2. **Stable ids everywhere** (ULIDs already used for resources, tags, projects,
   files, AI runs). Clients may mint ULIDs for offline creates.
3. **Optimistic concurrency** with the existing `version` column: every client
   write carries the base version; the server returns `409` with the current
   representation instead of overwriting. Two clients editing the same base version
   can never silently clobber each other.
4. **Idempotency keys** on every client-originated write (already supported), so
   replaying an outbox after a dropped connection is safe.

## Server additions (future, not implemented)

| Piece                                                                                            | Purpose                                                                                                                                                                                |
| ------------------------------------------------------------------------------------------------ | -------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| `change_log` table: `(owner_id, seq bigserial, entity_type, entity_id, version, op, changed_at)` | Per-owner ordered change feed. Written in the same transaction as the change (or derived from `activity_events`).                                                                      |
| `GET /api/v1/changes?cursor=<seq>&limit=500`                                                     | Returns ordered changes after a cursor + the next cursor. Clients page until empty.                                                                                                    |
| Tombstones                                                                                       | Permanent deletes write a tombstone row (`op = deleted`) kept for a retention window (e.g. 90 days). Clients older than the window do a full resync. Trash/restore are normal updates. |
| Attachment manifest                                                                              | Files are tracked separately: `GET /api/v1/files/{id}` with `ETag` = SHA-256; clients download lazily and upload originals via multipart create with an idempotency key.               |
| Conflict payload                                                                                 | Already exists: `409 version_conflict` with `details.current`.                                                                                                                         |

## Browser cache / outbox (future)

- IndexedDB stores: `resources` (last known server representation + version),
  `outbox` (queued mutations with idempotency key, base version, payload),
  `meta` (change-feed cursor).
- Reads render from IndexedDB, then refresh from `/changes`.
- Writes go to the outbox and are flushed in order when online. On `409`, the UI
  shows a field-level merge (server value vs local value) — never auto-overwrite.
- Uploads stay online-only at first (large binaries in IndexedDB are fragile).

## Desktop / local-agent companion (future)

- SQLite as a **local cache** with the same tables as the IndexedDB design, never
  as a second source of truth.
- The agent uses a token restricted to the abilities it needs (no
  `resources:delete`), the documented tool contracts in `docs/openapi.yaml`
  (`x-agent-tools`), idempotency keys, and version preconditions.

## Obsidian (future two-way adapter)

The current Obsidian export is a one-way snapshot. A future adapter could sync a
_selected set of front-matter properties_ (title, tags, read state) by id:

- store `stash_version` and a content hash of the synced properties in front matter;
- on push, send `PATCH` with `version = stash_version`; on `409`, write a conflict
  note instead of overwriting either side;
- never sync note bodies automatically; personal notes stay owned by Stash.

## Explicitly out of scope until then

No sync endpoints are shipped in production code now. Incomplete sync endpoints
would create a false sense of safety; they will be added together with the change
log, tombstones and client implementation above.
