# Brief: Option A (develop + master branches)

## Goal
Auto-deploy to two VPS from two branches, and show the git version in the app.

## Flow
```
feature → PR → develop ──push──▶ STAGING VPS   (shows v1.2.0-5-gabc1234)
                  │ test on staging
                  ▼
        master  git merge --ff-only develop
                git tag v1.3.0
                git push origin master --follow-tags
                  ──▶ LIVE VPS                 (shows v1.3.0)
```

## Rules
- `develop` is deployed to staging, `master` to live. Nothing else deploys.
- Merge `develop` into `master` with `--ff-only` (or GitHub "Rebase and merge"), so both branches share identical commit SHAs and therefore identical version strings.
- Tag releases (`vMAJOR.MINOR.PATCH`) on `master` only.
- Protect `master`: require PR and passing tests.

## Deploy steps on each VPS (`deploy.sh <branch>`)
1. `php artisan down`
2. `git fetch --tags --force`, `git checkout <branch>`, `git reset --hard origin/<branch>`
3. `composer install --no-interaction --optimize-autoloader` (`--no-dev` on master)
4. `php artisan migrate --force`
5. `git describe --tags --always > storage/app/version.txt` (before optimize)
6. `php artisan optimize:clear` then `php artisan optimize`
7. `php artisan queue:restart`, `php artisan up`

## Workflow trigger
```yaml
on:
  push:
    branches: [develop, master]
```
Target host/path is chosen by branch name (`master` → live, else staging).

## Version
`config('app.version')` reads `storage/app/version.txt`. Same string on both VPS = same code.

## Work needed
- [ ] Create `develop` branch, set as default for PRs
- [ ] Add `deploy.sh` and `.github/workflows/deploy.yml`
- [ ] GitHub secrets: `SSH_PRIVATE_KEY`, `SSH_USER`, `STAGING_HOST`, `LIVE_HOST`, `STAGING_PATH`, `LIVE_PATH`
- [ ] Deploy keys + clone repo on both VPS (staging on `develop`, live on `master`)
- [ ] Add `version` key to `config/app.php` and show it in the UI
- [ ] Branch protection on `master`

## Trade-offs
+ Easy mental model, no tag needed to reach staging.
- Two long-lived branches can drift; relies on discipline (`--ff-only`).
- Live deploys at the same moment as the push, so the tag must be pushed together (`--follow-tags`).

Full details: `docs/DEPLOYMENT.md`
