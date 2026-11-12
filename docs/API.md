# Stash API v1

The full machine-readable contract is **[`docs/openapi.yaml`](openapi.yaml)**
(OpenAPI 3.1). A test (`tests/Feature/Stash/ApiContractTest.php`) fails if any
`/api/v1` route is missing from it.

## Authentication & abilities

Create a token in **Settings → API tokens** (requires a recent password
confirmation). The token is shown once. Send it as a bearer token:

```bash
export STASH=https://stash.example.com/api/v1
export TOKEN=stash_...   # keep it in a secret manager
curl -H "Authorization: Bearer $TOKEN" $STASH/me
```

| Ability                            | Grants                                                                    |
| ---------------------------------- | ------------------------------------------------------------------------- |
| `resources:read`                   | search/list, details, files, AI run status                                |
| `resources:write`                  | create, update, archive/restore, trash/restore, refresh metadata, bulk    |
| `resources:delete`                 | permanent deletion (with `resources:write`); not an agent tool by default |
| `projects:read` / `projects:write` | project collection + item routes                                          |
| `tags:read` / `tags:write`         | tag collection + item routes                                              |
| `ai:run`                           | request summaries (spends the monthly token budget)                       |
| `data:export` / `data:import`      | exports and imports                                                       |

Abilities never bypass ownership: other owners' records answer `404`.

## Conventions

- JSON in/out; list responses are `{data, meta}`; single items `{data}`.
- Errors: `{"error": {"code", "message", "request_id", "details"}}` — codes such as
  `unauthenticated`, `missing_ability`, `not_found`, `validation_failed` (field
  errors in `details.fields`), `version_conflict`, `precondition_required`,
  `idempotency_key_reused`, `ai_disabled`, `ai_budget_exceeded`, `rate_limited`.
- `X-Request-Id` is echoed (or generated) on every response for log correlation.
- Page size default 24, maximum 100.
- Rate limits: 120 req/min per token; costly endpoints 20/min per owner.

## Examples

Save an article (idempotent):

```bash
curl -X POST $STASH/resources \
  -H "Authorization: Bearer $TOKEN" -H "Content-Type: application/json" \
  -H "Idempotency-Key: $(uuidgen)" \
  -d '{"url":"https://example.com/post","type":"article","tags":["Research"],"read_later":true}'
# 201 {"data":{"id":"01j…","processing":{"status":"queued"},"version":1,…},"meta":{"duplicates":[]}}
```

Upload a screenshot:

```bash
curl -X POST $STASH/resources -H "Authorization: Bearer $TOKEN" \
  -H "Idempotency-Key: shot-2026-11-12-1" \
  -F file=@dashboard.png -F title="Dashboard idea" -F "tags[]=UI/UX"
```

Search and filter (AND across groups, `tag_mode` within tags):

```bash
curl -G $STASH/resources -H "Authorization: Bearer $TOKEN" \
  --data-urlencode q=checkout -d "type[]=article" -d "tags[]=01j…" -d tag_mode=all \
  -d reading=unread -d from=2026-01-01 -d to=2026-03-31 -d sort=relevance -d per_page=50
```

Update with a version precondition:

```bash
curl -X PATCH $STASH/resources/01j… -H "Authorization: Bearer $TOKEN" \
  -H "Content-Type: application/json" -H 'If-Match: W/"3"' \
  -d '{"title":"Better title","tags":["Research","UX"],"reading_status":"read"}'
# 409 version_conflict → details.current holds the latest version; re-apply and retry.
```

Bulk:

```bash
curl -X POST $STASH/resources/bulk -H "Authorization: Bearer $TOKEN" -H "Content-Type: application/json" \
  -d '{"operation":"add_tags","ids":["01j…","01j…"],"tags":["Inbox zero"]}'
# {"data":{"changed":[…],"skipped":[{"id":"…","reason":"not_found"}],"undo":{…}}}
```

Summaries (optional; 503 `ai_disabled` when not configured):

```bash
# 1. see exactly what would be sent (free)
curl -X POST $STASH/resources/01j…/summaries -H "Authorization: Bearer $TOKEN" -H "Content-Type: application/json" \
  -d '{"source":"article","preview_only":true}'
# 2. send it (reserves budget, 202)
curl -X POST $STASH/resources/01j…/summaries -H "Authorization: Bearer $TOKEN" -H "Content-Type: application/json" \
  -H "Idempotency-Key: sum-01j…" -d '{"source":"article","text":"<text from step 1>"}'
# 3. poll
curl $STASH/ai-runs/01j… -H "Authorization: Bearer $TOKEN"
```

Export & import:

```bash
curl -X POST $STASH/exports -H "Authorization: Bearer $TOKEN" -H "Content-Type: application/json" -d '{"format":"zip"}'
curl $STASH/exports/01j… -H "Authorization: Bearer $TOKEN"            # wait for status=ready
curl -OJ $STASH/exports/01j…/download -H "Authorization: Bearer $TOKEN"

curl -X POST $STASH/imports -H "Authorization: Bearer $TOKEN" -F file=@stash-export.zip
curl $STASH/imports/01j… -H "Authorization: Bearer $TOKEN"             # preview: collisions, duplicates, errors
curl -X POST $STASH/imports/01j…/apply -H "Authorization: Bearer $TOKEN" -H "Content-Type: application/json" -d '{"collision_strategy":"skip"}'
```

## Agent tool contracts

`docs/openapi.yaml` → `x-agent-tools` maps the tools `search_resources`,
`create_resource`, `update_resource`, `assign_tags`, `assign_project`,
`summarize_resource` and `archive_resource` to operations and minimum abilities.
There is no SQL, shell or permanent-delete tool. A conversational runtime or MCP
server is future work; these contracts are what it would call. Recommended agent
token: `resources:read resources:write tags:read projects:read` (+ `ai:run` if it
may spend budget).
