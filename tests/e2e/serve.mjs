// Boots an isolated Stash instance for Playwright:
//  1. points Laravel at the disposable `stash_e2e` database (env overrides .env)
//  2. migrate:fresh (E2E database only) and creates the test owner
//  3. serves the built assets with PHP's built-in server and runs a real
//     Redis queue worker (isolated key prefix), like production
// Requires `npm run build` beforehand (CI runs it as part of the checks).
import { spawn, spawnSync } from 'node:child_process';
import { existsSync, readFileSync } from 'node:fs';
import { fileURLToPath } from 'node:url';
import path from 'node:path';

const root = path.resolve(
    path.dirname(fileURLToPath(import.meta.url)),
    '../..',
);
const envFile = path.join(root, 'tests/e2e/.env.e2e');
const exampleFile = path.join(root, 'tests/e2e/.env.e2e.example');
const values = Object.fromEntries(
    readFileSync(existsSync(envFile) ? envFile : exampleFile, 'utf8')
        .split(/\r?\n/)
        .filter((line) => line && !line.startsWith('#') && line.includes('='))
        .map((line) => [
            line.slice(0, line.indexOf('=')).trim(),
            line.slice(line.indexOf('=') + 1).trim(),
        ]),
);

const port = process.env.E2E_PORT ?? '8010';
const env = {
    ...process.env,
    APP_ENV: 'local',
    APP_DEBUG: 'true',
    APP_URL: `http://127.0.0.1:${port}`,
    DB_DATABASE: process.env.E2E_DB_DATABASE ?? 'stash_e2e',
    QUEUE_CONNECTION: 'redis',
    REDIS_PREFIX: 'stash-e2e-',
    CACHE_STORE: 'file',
    SESSION_DRIVER: 'file',
    STASH_STORAGE_ROOT: path.join(root, 'storage/app/private/stash-e2e'),
    STASH_FETCH_TIMEOUT: '3',
    STASH_FETCH_CONNECT_TIMEOUT: '2',
    AI_ENABLED: 'true',
    AI_PROVIDER: 'fake',
    E2E_PASSWORD: process.env.E2E_PASSWORD ?? values.E2E_PASSWORD,
};

if (!env.DB_DATABASE.endsWith('_e2e')) {
    console.error(
        'Refusing to reset a database whose name does not end in _e2e.',
    );
    process.exit(1);
}

const artisan = (...args) => {
    const r = spawnSync('php', ['artisan', ...args], {
        cwd: root,
        env,
        stdio: 'inherit',
    });
    if (r.status !== 0) {
        process.exit(r.status ?? 1);
    }
};

artisan('config:clear');
artisan('migrate:fresh', '--force');
artisan(
    'stash:create-owner',
    '--name=E2E Owner',
    `--email=${process.env.E2E_EMAIL ?? values.E2E_EMAIL}`,
    '--password-from-env=E2E_PASSWORD',
    '--timezone=Asia/Jakarta',
);

artisan('queue:clear', 'redis', '--queue=default', '--force');
artisan('queue:clear', 'redis', '--queue=ai', '--force');

const worker = spawn(
    'php',
    [
        'artisan',
        'queue:work',
        'redis',
        '--queue=default,ai',
        '--sleep=1',
        '--tries=3',
        '--timeout=120',
    ],
    { cwd: root, env, stdio: 'inherit' },
);

const server = spawn(
    'php',
    [
        '-d',
        'upload_max_filesize=12M',
        '-d',
        'post_max_size=14M',
        '-d',
        'memory_limit=512M',
        '-S',
        `127.0.0.1:${port}`,
        '-t',
        'public',
        'scripts/dev-router.php',
    ],
    { cwd: root, env, stdio: 'inherit' },
);
const stop = () => {
    server.kill();
    worker.kill();
};
process.on('SIGINT', stop);
process.on('SIGTERM', stop);
process.on('exit', stop);
