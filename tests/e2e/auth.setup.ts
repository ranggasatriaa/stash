import { expect, test as setup } from '@playwright/test';
import { readFileSync } from 'node:fs';
import path from 'node:path';
import { fileURLToPath } from 'node:url';

// Log in once and share the session: Fortify throttles logins to 5/minute.
const dir = path.dirname(fileURLToPath(import.meta.url));
const env = Object.fromEntries(
    readFileSync(path.join(dir, '.env.e2e.example'), 'utf8')
        .split(/\r?\n/)
        .filter((l) => l.includes('=') && !l.startsWith('#'))
        .map((l) => [l.slice(0, l.indexOf('=')), l.slice(l.indexOf('=') + 1)]),
);

export const STORAGE_STATE = 'storage/playwright-results/.auth/owner.json';

setup('authenticate', async ({ page }) => {
    await page.goto('/login');
    await page
        .getByLabel('Email address')
        .fill(process.env.E2E_EMAIL ?? env.E2E_EMAIL);
    await page
        .getByLabel('Password', { exact: true })
        .fill(process.env.E2E_PASSWORD ?? env.E2E_PASSWORD);
    await page.getByRole('button', { name: 'Log in' }).click();
    await expect(page).toHaveURL(/\/library$/);
    await page.context().storageState({ path: STORAGE_STATE });
});
