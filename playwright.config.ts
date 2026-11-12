import { defineConfig, devices } from '@playwright/test';

/**
 * End-to-end tests against a real PHP server and the disposable
 * `stash_e2e` PostgreSQL database (see tests/e2e/serve.mjs). The database
 * is reset on every run. Credentials are local test values only.
 */
const port = Number(process.env.E2E_PORT ?? 8010);
const baseURL = process.env.E2E_BASE_URL ?? `http://127.0.0.1:${port}`;

export default defineConfig({
    testDir: './tests/e2e',
    fullyParallel: false,
    workers: 1,
    retries: 0,
    timeout: 60_000,
    expect: { timeout: 10_000 },
    reporter: [
        ['list'],
        ['html', { open: 'never', outputFolder: 'storage/playwright-report' }],
    ],
    outputDir: 'storage/playwright-results',
    use: {
        baseURL,
        trace: 'retain-on-failure',
        screenshot: 'only-on-failure',
        locale: 'en-GB',
        timezoneId: 'Asia/Jakarta',
    },
    projects: [
        { name: 'setup', testMatch: /auth\.setup\.ts/ },
        {
            name: 'desktop',
            dependencies: ['setup'],
            grepInvert: /@mobile/,
            use: {
                ...devices['Desktop Chrome'],
                viewport: { width: 1440, height: 900 },
                storageState: 'storage/playwright-results/.auth/owner.json',
            },
        },
        {
            name: 'mobile',
            dependencies: ['setup'],
            grep: /@mobile/,
            use: {
                ...devices['Pixel 7'],
                viewport: { width: 390, height: 844 },
                storageState: 'storage/playwright-results/.auth/owner.json',
            },
        },
    ],
    webServer: {
        command: 'node tests/e2e/serve.mjs',
        url: `${baseURL}/up`,
        reuseExistingServer: false,
        timeout: 120_000,
        stdout: 'ignore',
        stderr: 'ignore',
    },
});
