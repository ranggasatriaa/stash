# Brief: Option B (single master branch, live deploys by tag)

## Goal
Auto-deploy to two VPS from one branch, and show the git version in the app.

## Flow
```
feature → PR → master ──push──▶ STAGING VPS   (shows v1.2.0-5-gabc1234)
                  │ test on staging
                  ▼
        git tag v1.3.0
        git push origin v1.3.0
                  ──▶ LIVE VPS                 (shows v1.3.0)
```

## Rules
- Every push to `master` deploys to staging.
- Live deploys **only** when a tag `v*` is pushed. The tag points at a commit already tested on staging.
- Staging and live always run commits from the same single history, so versions cannot drift. No `--ff-only` needed.
- Rollback = redeploy an older tag (`git push origin v1.2.0 --force` or "Re-run" the tag's workflow run).
- Protect `master`: require PR and passing tests. Protect tags `v*` (Settings → Tags) so only you can create them.

## Deploy steps on each VPS (`deploy.sh <ref>`)
1. `php artisan down`
2. `git fetch --tags --force`, then `git checkout --force <ref>` (`origin/master` for staging, the tag for live)
3. `composer install --no-interaction --optimize-autoloader` (`--no-dev` on live)
4. `php artisan migrate --force`
5. `git describe --tags --always > storage/app/version.txt` (before optimize)
6. `php artisan optimize:clear` then `php artisan optimize`
7. `php artisan queue:restart`, `php artisan up`

Both VPS end on a detached HEAD or a reset `master`; that is fine because nothing is committed on the servers.

## Workflow trigger
```yaml
on:
  push:
    branches: [master]    # → staging
    tags: ['v*']          # → live
```
Target is chosen with:
```bash
if [[ "$GITHUB_REF" == refs/tags/* ]]; then  # live, ref = $GITHUB_REF_NAME
else                                          # staging, ref = origin/master
```

## Version
`config('app.version')` reads `storage/app/version.txt`.
Live: `v1.3.0`. Staging: `v1.3.0-4-gSHA` while ahead of the last tag, and equal to live once the tag is released.

## Optional: make live a button
Add `workflow_dispatch` with a `tag` input, or use a GitHub Environment named `live` with required reviewers, so a tag push waits for your approval before deploying.

## Work needed
- [ ] Add `deploy.sh` and `.github/workflows/deploy.yml` (triggers above)
- [ ] GitHub secrets: `SSH_PRIVATE_KEY`, `SSH_USER`, `STAGING_HOST`, `LIVE_HOST`, `STAGING_PATH`, `LIVE_PATH`
- [ ] Deploy keys + clone repo on both VPS
- [ ] Add `version` key to `config/app.php` and show it in the UI
- [ ] Branch protection on `master`, tag protection on `v*`
- [ ] Decide: manual tags, or add auto-tag/approval gate

## Trade-offs
+ One branch, no drift, version sync is automatic, easy rollback.
+ Live always equals a named, tested version.
- You must remember to tag to release (or add a manual gate).
- Unfinished work on `master` goes to staging immediately, so use feature branches/PRs for anything half-done.

Full script and key setup: `docs/DEPLOYMENT.md` (replace the branch-based trigger with the one above).
