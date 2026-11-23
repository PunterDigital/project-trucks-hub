import type { Page, Request } from '@playwright/test';
import { expect, test } from './fixtures';

// #4723: an open page refreshes its props once a minute while the tab is visible.
// Playwright's clock stands in for the minute; a second browser context changes the data.

const PROFILE = '/drivers/rita-refresh';

/** Inertia partial reloads of the profile (not the first full page load). */
function track(page: Page): Request[] {
    const reloads: Request[] = [];
    page.on('request', (req) => {
        if (
            req.url().endsWith(PROFILE) &&
            req.headers()['x-inertia'] === 'true'
        ) {
            reloads.push(req);
        }
    });

    return reloads;
}

async function setVisible(page: Page, visible: boolean): Promise<void> {
    await page.evaluate((v) => {
        Object.defineProperty(document, 'visibilityState', {
            configurable: true,
            get: () => (v ? 'visible' : 'hidden'),
        });
        Object.defineProperty(document, 'hidden', {
            configurable: true,
            get: () => !v,
        });
        document.dispatchEvent(new Event('visibilitychange'));
    }, visible);
}

test('an open page picks up new data after a minute, and a hidden tab waits', async ({
    page,
    browser,
}) => {
    await page.clock.install();
    const reloads = track(page);
    await page.goto(PROFILE);
    const heading = page.getByRole('heading', { level: 1 });
    await expect(heading).toContainText('Rita Refresh');
    await page.evaluate(() => window.scrollTo(0, 0));

    // Rita renames herself elsewhere.
    const other = await browser.newContext();
    const rita = await other.newPage();
    await rita.goto('/login');
    await rita.getByLabel('Email address').fill('rita-refresh@example.com');
    await rita.getByLabel('Password', { exact: true }).fill('password');
    await rita.getByRole('button', { name: 'Log in' }).click();
    await expect(rita).toHaveURL(/\/dashboard$/);
    await rita.goto('/settings/profile');
    await rita.getByLabel('Name', { exact: true }).fill('Rita Renamed');
    await rita.locator('[data-test="update-profile-button"]').click();
    await expect(rita.getByText('Profile updated.')).toBeVisible();

    // Before the minute is up the guest's page still shows the old name...
    await page.clock.fastForward(30_000);
    await expect(heading).toContainText('Rita Refresh');
    expect(reloads).toHaveLength(0);

    // ...and after it, the new one, with no full page load.
    await page.clock.fastForward(31_000);
    await expect(heading).toContainText('Rita Renamed');
    expect(reloads).toHaveLength(1);
    // Only the profile's live props, the bell and the user (#5731), not every prop.
    expect(reloads[0].headers()['x-inertia-partial-component']).toBe(
        'drivers/Show',
    );
    expect(reloads[0].headers()['x-inertia-partial-data']).toBe(
        'driver,live,jobs,stats,auth,notifications',
    );
    // Props it didn't ask for stay on the page as they were.
    await expect(page.getByRole('tab', { name: /Achievements/ })).toBeVisible();

    // A hidden tab doesn't poll at all...
    await setVisible(page, false);
    await rita.getByLabel('Name', { exact: true }).fill('Rita Away');
    await rita.locator('[data-test="update-profile-button"]').click();
    await expect(rita.getByText('Profile updated.').last()).toBeVisible();
    await page.clock.fastForward(5 * 60_000);
    expect(reloads).toHaveLength(1);
    await expect(heading).toContainText('Rita Renamed');

    // ...and refreshes as soon as it's back, since its data is over a minute old.
    await setVisible(page, true);
    await expect(heading).toContainText('Rita Away');
    expect(reloads).toHaveLength(2);

    await other.close();
});

test('no refresh while the visitor is typing in a field', async ({ page }) => {
    await page.clock.install();
    const reloads: Request[] = [];
    page.on('request', (req) => {
        if (req.headers()['x-inertia'] === 'true') {
            reloads.push(req);
        }
    });
    await page.goto('/login');
    await page.getByLabel('Email address').fill('typing@example.com');

    // Auth pages have no app layout, so no timer at all.
    await page.clock.fastForward(2 * 60_000);
    expect(reloads).toHaveLength(0);

    // A page with the layout: focus a search-style field and wait it out.
    await page.goto(PROFILE);
    await expect(page.getByRole('heading', { level: 1 })).toBeVisible();
    await page.evaluate(() => {
        const input = document.createElement('input');
        input.type = 'text';
        input.setAttribute('data-test', 'probe');
        document.body.appendChild(input);
        input.focus();
    });
    const before = reloads.length;
    await page.clock.fastForward(2 * 60_000);
    expect(reloads.length).toBe(before);

    // Leave the field: the next tick refreshes.
    await page.locator('[data-test="probe"]').blur();
    await page.clock.fastForward(61_000);
    await expect.poll(() => reloads.length).toBe(before + 1);
});
