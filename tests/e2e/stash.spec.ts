import { expect, test } from '@playwright/test';
import type { Page } from '@playwright/test';
import { readFileSync } from 'node:fs';
import path from 'node:path';
import { fileURLToPath } from 'node:url';

const __dirname = path.dirname(fileURLToPath(import.meta.url));

/*
 * Browser flows required by the spec (§14.11):
 * login → save → edit tags/project → filter/search → read/archive → restore,
 * upload screenshot, export, keyboard modal interaction, mobile usability.
 */

const env = Object.fromEntries(
    readFileSync(path.join(__dirname, '.env.e2e.example'), 'utf8')
        .split(/\r?\n/)
        .filter((l) => l.includes('=') && !l.startsWith('#'))
        .map((l) => [l.slice(0, l.indexOf('=')), l.slice(l.indexOf('=') + 1)]),
);
const EMAIL = process.env.E2E_EMAIL ?? env.E2E_EMAIL;
const PASSWORD = process.env.E2E_PASSWORD ?? env.E2E_PASSWORD;

/**
 * Sessions come from auth.setup.ts (Fortify throttles logins to 5/minute);
 * the form is only used when the page is logged out.
 */
async function login(page: Page) {
    await page.goto('/library');
    if (page.url().includes('/login')) {
        await page.getByLabel('Email address').fill(EMAIL);
        await page.getByLabel('Password', { exact: true }).fill(PASSWORD);
        await page.getByRole('button', { name: 'Log in' }).click();
    }
    await expect(page).toHaveURL(/\/library$/);
}

async function expectNoHorizontalOverflow(page: Page) {
    const overflow = await page.evaluate(
        () => document.documentElement.scrollWidth - window.innerWidth,
    );
    expect(overflow).toBeLessThanOrEqual(0);
}

async function toast(page: Page, text: string | RegExp) {
    await expect(
        page.locator('.st-toast').filter({ hasText: text }).first(),
    ).toBeVisible();
}

test.describe.configure({ mode: 'serial' });

test('login → save → edit tags/project → search/filter → read → archive → restore', async ({
    browser,
}) => {
    // Start logged out so the real login form is exercised.
    const context = await browser.newContext({
        storageState: { cookies: [], origins: [] },
    });
    const page = await context.newPage();
    await page.goto('/library');
    await expect(page).toHaveURL(/\/login$/);
    await login(page);
    await expect(
        page.getByRole('heading', { name: 'All saved' }),
    ).toBeVisible();

    // A project to file things under.
    await page.goto('/projects');
    await page.getByRole('button', { name: 'New project' }).first().click();
    await page.getByLabel('Name').fill('Website Redesign');
    await page.getByRole('button', { name: 'Save', exact: true }).click();
    await toast(page, 'Project created.');

    // Save an article.
    await page.goto('/library');
    await page
        .locator('main')
        .getByRole('button', { name: 'Save', exact: true })
        .click();
    const dialog = page.getByRole('dialog', { name: 'Save a resource' });
    await expect(dialog).toBeVisible();
    await expect(dialog.getByLabel('URL')).toBeFocused();
    await dialog.getByLabel('URL').fill('https://example.com/e2e-checkout');
    await dialog.getByRole('radio', { name: 'Article' }).click();
    await dialog.getByLabel('Title').fill('E2E checkout reference');
    const tags = dialog.getByRole('combobox', { name: 'Tags' });
    await tags.fill('UI/UX');
    await tags.press('Enter');
    await tags.fill('Inspiration');
    await tags.press('Enter');
    await dialog
        .getByLabel('Project')
        .selectOption({ label: 'Website Redesign' });
    await dialog.getByRole('button', { name: /^Save( anyway)?$/ }).click();
    await toast(page, /Resource saved|Saved/);
    await expect(dialog).toBeHidden();

    const card = page
        .getByTestId('resource-card')
        .filter({ hasText: 'E2E checkout reference' });
    await expect(card).toBeVisible();
    await expect(card.getByText('UI/UX')).toBeVisible();
    await expect(card.getByText('Website Redesign')).toBeVisible();

    // Edit tags + project in the detail panel.
    await card.getByRole('link', { name: 'E2E checkout reference' }).click();
    const panel = page.getByRole('dialog', { name: 'Resource details' });
    await expect(panel).toBeVisible();
    const detailTags = panel.getByRole('combobox', { name: 'Tags' });
    await detailTags.fill('Research');
    await detailTags.press('Enter');
    await panel.getByLabel('Project').selectOption({ label: 'No project' });
    await panel.getByRole('button', { name: 'Save changes' }).click();
    await toast(page, 'Changes saved.');
    await expect(
        panel.getByText(/All changes saved · version \d+/),
    ).toBeVisible();
    await page.keyboard.press('Escape');
    await expect(panel).toBeHidden();
    await expect(card.getByText('Research')).toBeVisible();
    await expect(card.getByText('Website Redesign')).toHaveCount(0);

    // Search (debounced, persisted in the URL).
    await page.getByLabel('Search your stash').fill('checkout');
    await expect(page).toHaveURL(/q=checkout/);
    await expect(page.getByTestId('resource-card')).toHaveCount(1);
    await page.getByLabel('Search your stash').fill('zzzz-no-match');
    await expect(
        page.getByRole('heading', { name: 'No results' }),
    ).toBeVisible();
    await page.getByRole('button', { name: 'Clear filters' }).first().click();
    await expect(page.getByTestId('resource-card')).toHaveCount(1);

    // Tag filter through the custom filter panel + chips.
    await page.getByRole('button', { name: /Filters/ }).click();
    await page.getByRole('button', { name: /^Research/ }).click();
    await page.getByRole('button', { name: 'Apply filters' }).click();
    await expect(
        page.locator('.st-chip').filter({ hasText: 'Tag: Research' }),
    ).toBeVisible();
    await expect(page.getByTestId('resource-card')).toHaveCount(1);
    await page.reload();
    await expect(
        page.locator('.st-chip').filter({ hasText: 'Tag: Research' }),
    ).toBeVisible();
    await page
        .getByRole('button', { name: 'Remove filter Tag: Research' })
        .click();
    await expect(page.locator('.st-chip')).toHaveCount(0);

    // Mark read, then archive.
    await card.getByRole('link', { name: 'E2E checkout reference' }).click();
    await panel.getByRole('button', { name: 'Mark read' }).click();
    await expect(
        panel.getByText('Read', { exact: true }).first(),
    ).toBeVisible();
    await panel.getByRole('button', { name: 'Archive', exact: true }).click();
    await toast(page, 'Archived.');
    await page.keyboard.press('Escape');
    await expect(
        page
            .getByTestId('resource-card')
            .filter({ hasText: 'E2E checkout reference' }),
    ).toHaveCount(0);

    // Restore from the Archive section.
    await page.goto('/library/archive');
    const archived = page
        .getByTestId('resource-card')
        .filter({ hasText: 'E2E checkout reference' });
    await expect(archived).toBeVisible();
    await archived
        .getByRole('link', { name: 'E2E checkout reference' })
        .click();
    await page
        .getByRole('dialog', { name: 'Resource details' })
        .getByRole('button', { name: 'Restore from Archive' })
        .click();
    await toast(page, 'Restored from Archive.');
    await page.goto('/library');
    await expect(
        page
            .getByTestId('resource-card')
            .filter({ hasText: 'E2E checkout reference' }),
    ).toBeVisible();
});

test('upload a screenshot with preview generation', async ({ page }) => {
    await login(page);
    await page
        .locator('main')
        .getByRole('button', { name: 'Save', exact: true })
        .click();
    const dialog = page.getByRole('dialog', { name: 'Save a resource' });
    await dialog.getByRole('tab', { name: 'Upload image' }).click();
    await dialog
        .locator('input[type=file]')
        .setInputFiles(path.join(__dirname, 'fixtures/screenshot.png'));
    await expect(dialog.getByText('screenshot.png')).toBeVisible();
    await dialog.getByLabel('Title').fill('E2E dashboard screenshot');
    await dialog.getByRole('button', { name: /^Save/ }).click();
    await toast(page, /Resource saved|Saved/);

    await page.goto('/library/screenshots');
    const card = page
        .getByTestId('resource-card')
        .filter({ hasText: 'E2E dashboard screenshot' });
    await expect(card).toBeVisible();
    // Variants are generated by the real queue worker; the page polls.
    const img = card.locator('.st-card__media img');
    await expect(img).toBeVisible({ timeout: 30_000 });
    const src = await img.getAttribute('src');
    const res = await page.request.get(src!);
    expect(res.status()).toBe(200);
    expect(res.headers()['content-type']).toBe('image/webp');

    // Rejected file type gives an actionable error.
    await page
        .locator('main')
        .getByRole('button', { name: 'Save', exact: true })
        .click();
    await page.getByRole('tab', { name: 'Upload image' }).click();
    await page.locator('input[type=file]').setInputFiles({
        name: 'notes.txt',
        mimeType: 'text/plain',
        buffer: Buffer.from('hello'),
    });
    await expect(
        page.getByRole('alert').filter({ hasText: 'not a supported image' }),
    ).toBeVisible();
});

test('bulk archive with undo', async ({ page }) => {
    await login(page);
    const cards = page.getByTestId('resource-card');
    const count = await cards.count();
    expect(count).toBeGreaterThanOrEqual(2);
    await page.getByLabel('Select page').check();
    const bar = page.getByRole('region', { name: 'Bulk actions' });
    await expect(bar.getByText(`${count} selected`)).toBeVisible();
    await bar.getByRole('button', { name: 'Archive' }).click();
    await toast(page, new RegExp(`Archived: ${count} items`));
    await expect(
        page.getByRole('heading', { name: 'Your stash starts here' }),
    ).toBeVisible();
    await page
        .locator('.st-toast')
        .getByRole('button', { name: 'Undo' })
        .click();
    await expect(cards).toHaveCount(count);
});

test('export metadata as JSON', async ({ page }) => {
    await login(page);
    await page.goto('/settings/data');
    await page.getByText('Metadata only (JSON)').click();
    await page.getByRole('button', { name: 'Create export' }).click();
    const link = page.getByRole('link', { name: 'Download' }).first();
    await expect(link).toBeVisible({ timeout: 20_000 });
    const res = await page.request.get((await link.getAttribute('href'))!);
    expect(res.status()).toBe(200);
    const doc = await res.json();
    expect(doc.schema).toBe('stash.export');
    expect(doc.counts.resources).toBeGreaterThanOrEqual(2);
});

test('keyboard: Save dialog traps focus, Escape closes and restores focus', async ({
    page,
}) => {
    await login(page);
    const trigger = page
        .locator('main')
        .getByRole('button', { name: 'Save', exact: true });
    await trigger.focus();
    await page.keyboard.press('Enter');
    const dialog = page.getByRole('dialog', { name: 'Save a resource' });
    await expect(dialog).toBeVisible();
    await expect(dialog.getByLabel('URL')).toBeFocused();
    for (let i = 0; i < 25; i++) {
        await page.keyboard.press('Tab');
        const inside = await dialog.evaluate((el) =>
            el.contains(document.activeElement),
        );
        expect(inside).toBe(true);
    }
    await page.keyboard.press('Escape');
    await expect(dialog).toBeHidden();
    await expect(trigger).toBeFocused();

    // "/" focuses search.
    await page.keyboard.press('/');
    await expect(page.getByLabel('Search your stash')).toBeFocused();
});

test('optional AI summary with the offline fake provider', async ({ page }) => {
    await login(page);
    const card = page
        .getByTestId('resource-card')
        .filter({ hasText: 'E2E checkout reference' });
    await card.getByRole('link', { name: 'E2E checkout reference' }).click();
    const panel = page.getByRole('dialog', { name: 'Resource details' });
    await panel.getByRole('button', { name: 'Summarize' }).click();
    const ai = page.getByRole('dialog', { name: 'Summarize article' });
    await ai.getByLabel('Paste the article text myself').check();
    await ai
        .getByRole('textbox', { name: 'Article text' })
        .fill(
            'Checkout flows should be short, transparent about costs and forgiving of mistakes. Good forms validate inline.',
        );
    await ai.getByRole('button', { name: 'Preview what will be sent' }).click();
    await expect(ai.getByLabel('Text that will be sent')).toContainText(
        'Checkout flows should be short',
    );
    await ai.getByRole('button', { name: 'Send to provider' }).click();
    await expect(panel.getByText(/^Summary of \d+ words/)).toBeVisible({
        timeout: 30_000,
    });
    await expect(panel.getByText(/AI-generated/)).toBeVisible();
});

test('mobile layout at 390px and 320px @mobile', async ({ page }) => {
    await login(page);
    await expect(
        page.getByRole('navigation', { name: 'Primary' }),
    ).toBeVisible();
    await expectNoHorizontalOverflow(page);

    await page.getByRole('button', { name: 'More' }).click();
    const drawer = page.getByRole('dialog', { name: 'Navigation' });
    await expect(drawer.getByRole('link', { name: /Trash/ })).toBeVisible();
    await drawer.getByRole('link', { name: /Archive/ }).click();
    await expect(page).toHaveURL(/\/library\/archive/);

    await page.goto('/library');
    await page
        .getByRole('banner')
        .getByRole('button', { name: 'Save' })
        .click();
    await expect(
        page.getByRole('dialog', { name: 'Save a resource' }),
    ).toBeVisible();
    await expectNoHorizontalOverflow(page);
    await page.keyboard.press('Escape');

    for (const width of [390, 320]) {
        await page.setViewportSize({ width, height: 760 });
        for (const url of [
            '/library',
            '/library/screenshots',
            '/projects',
            '/tags',
            '/settings/data',
            '/settings/preferences',
        ]) {
            await page.goto(url);
            await expectNoHorizontalOverflow(page);
        }
        await page.goto('/library');
        await page
            .getByTestId('resource-card')
            .first()
            .getByRole('heading')
            .getByRole('link')
            .click();
        await expect(
            page.getByRole('dialog', { name: 'Resource details' }),
        ).toBeVisible();
        await expectNoHorizontalOverflow(page);
    }
});
