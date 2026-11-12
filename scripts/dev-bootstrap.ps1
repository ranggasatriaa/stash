# Stash local development bootstrap for Windows PowerShell.
# Safe to re-run: never drops data. See scripts/dev-bootstrap.sh for details.
$ErrorActionPreference = 'Stop'
Set-Location (Join-Path $PSScriptRoot '..')

foreach ($cmd in 'php', 'composer', 'node', 'npm') {
    if (-not (Get-Command $cmd -ErrorAction SilentlyContinue)) { throw "Missing dependency: $cmd" }
}

if (-not (Test-Path .env)) {
    Copy-Item .env.example .env
    Write-Host 'Created .env from .env.example - edit DB_* and REDIS_* if needed.'
}

composer install --no-interaction
if ($LASTEXITCODE -ne 0) { throw 'composer install failed' }
npm ci --no-audit --no-fund
if ($LASTEXITCODE -ne 0) { throw 'npm ci failed' }

if (-not (Select-String -Path .env -Pattern '^APP_KEY=base64:' -Quiet)) { php artisan key:generate }

php artisan migrate --force
if ($LASTEXITCODE -ne 0) { throw 'migrate failed - check DB_* in .env' }

php artisan wayfinder:generate --with-form | Out-Null
npm run build
if ($LASTEXITCODE -ne 0) { throw 'asset build failed' }

Write-Host @'

Stash is ready. Next steps:
  php artisan stash:create-owner
  php artisan stash:demo-seed          (optional, local only)
  php -d upload_max_filesize=12M -d post_max_size=14M -S 127.0.0.1:8000 -t public scripts/dev-router.php
  php artisan queue:work redis --queue=default,ai
'@
