import type { Page } from '@playwright/test';
import { expect, logIn, test } from './fixtures';
import { logInWithTotp } from './totp';

// #4664: the admin panel at /admin. Seeded staff: sam@example.com (2FA on).
const SAM_SECRET = 'JBSWY3DPEHPK3PXPJBSWY3DPEHPK3PXP';

async function logInAsStaff(page: Page): Promise<void> {
    await logInWithTotp(page, 'sam@example.com', SAM_SECRET);
}

test('guests and drivers get a 404 at /admin, with no Admin link', async ({
    page,
    consoleErrors,
}) => {
    // Chrome logs every 404 page load as a console error: those are expected here.
    const forget404s = () => {
        expect(consoleErrors.every((e) => e.includes('404'))).toBe(true);
        consoleErrors.splice(0);
    };

    const guest = await page.goto('/admin');
    expect(guest?.status()).toBe(404);
    forget404s();

    await logIn(page, 'erin@example.com');
    await page.locator('[data-test="sidebar-menu-button"]').click();
    await expect(page.locator('[data-test="logout-button"]')).toBeVisible();
    await expect(page.locator('[data-test="admin-link"]')).toHaveCount(0);

    const driver = await page.goto('/admin/users');
    expect(driver?.status()).toBe(404);
    forget404s();
});

test('staff open the panel from the user menu and every resource', async ({
    page,
    consoleErrors,
}) => {
    await logInAsStaff(page);

    await page.locator('[data-test="sidebar-menu-button"]').click();
    await page.locator('[data-test="admin-link"]').click();
    await expect(page).toHaveURL(/\/admin$/);
    await expect(
        page.getByRole('heading', { name: 'Dashboard' }),
    ).toBeVisible();

    // #4665: platform stats. Widgets load lazily, so wait on what they show.
    await expect(page.getByText('Email sign-ups')).toBeVisible();
    await expect(page.getByText('On the road now')).toBeVisible();
    await expect(
        page.getByText('Last 7 days, compared with the 7 days before'),
    ).toBeVisible();
    for (const chart of [
        'Sign-ups per day',
        'Jobs synced per day',
        'Active drivers per day',
        'ProjectTrucks link requests per day',
    ]) {
        await expect(page.getByRole('heading', { name: chart })).toBeVisible();
    }
    // The tables load once scrolled into view.
    const flaggedHeading = page.getByRole('heading', {
        name: 'Recently flagged jobs',
    });
    await expect(async () => {
        await page.mouse.wheel(0, 2000);
        await expect(flaggedHeading).toBeVisible({ timeout: 1000 });
    }).toPass();
    await expect(
        page.getByRole('heading', { name: 'Most active drivers this week' }),
    ).toBeVisible();
    // Recent sign-ups lists seeded accounts (some are backdated, so no fixed name).
    await expect(
        page
            .getByRole('heading', { name: 'Recent sign-ups' })
            .locator('xpath=ancestor::*[.//table][1]')
            .locator('tbody tr')
            .first(),
    ).toBeVisible();
    await page.getByLabel('Period').selectOption('30');
    await expect(
        page.getByText('Last 30 days, compared with the 30 days before'),
    ).toBeVisible();
    await page.getByLabel('Period').selectOption('7');

    // Users: search by name, open the driver, revoke (none linked, still audited).
    await page.getByRole('link', { name: 'Users' }).first().click();
    await expect(page).toHaveURL(/\/admin\/users$/);
    // The list is paginated, newest first: newer seeded drivers push Olga off page one.
    await page.goto('/admin/users?search=olga');
    await expect(page.getByText('olga@example.com')).toBeVisible();
    await page.getByRole('link', { name: /^Olga/ }).first().click();
    await expect(page).toHaveURL(/\/admin\/users\/\d+$/);
    await expect(page.getByText('Nordic Haulage').first()).toBeVisible();
    // Filament's confirmation is an alertdialog; a click before Livewire has loaded opens
    // nothing, so retry until its heading shows.
    const heading = page.getByRole('heading', {
        name: 'Revoke all linked apps?',
    });
    await expect(async () => {
        await page.getByRole('button', { name: 'Revoke linked apps' }).click();
        await expect(heading).toBeVisible({ timeout: 2000 });
    }).toPass();
    const dialog = page.getByRole('alertdialog');
    await dialog.getByRole('button', { name: 'Revoke' }).click();
    await expect(page.getByText(/linked apps? revoked/)).toBeVisible();

    await page.getByRole('link', { name: 'Companies' }).first().click();
    await expect(page).toHaveURL(/\/admin\/companies$/);
    await page
        .getByRole('link', { name: /Nordic Haulage/ })
        .first()
        .click();
    await expect(page).toHaveURL(/\/admin\/companies\/\d+$/);
    await expect(page.getByRole('heading', { name: 'Members' })).toBeVisible();

    await page.getByRole('link', { name: 'Jobs' }).first().click();
    await expect(page).toHaveURL(/\/admin\/jobs$/);
    await expect(page.locator('.fi-ta-row').first()).toBeVisible();
    await page.locator('.fi-ta-row').first().getByRole('link').first().click();
    await expect(page).toHaveURL(/\/admin\/jobs\/\d+$/);
    await expect(page.getByRole('heading', { name: 'Checks' })).toBeVisible();

    await page.getByRole('link', { name: 'Audit log' }).first().click();
    await expect(page).toHaveURL(/\/admin\/audit-log$/);
    // Scoped to the rows: the action filter's options hold the same text.
    const row = page
        .locator('.fi-ta-row')
        .filter({ hasText: 'user.linked_apps_revoked' });
    await expect(row.first()).toBeVisible();
    await expect(row.first()).toContainText('Sam Staff');
    await expect(row.first()).toContainText('Olga');

    // #4666: System → Database and Scheduler. E2E runs no scheduler, so it says so.
    await page.getByRole('link', { name: 'Database' }).first().click();
    await expect(page).toHaveURL(/\/admin\/database$/);
    await expect(page.getByText('Total size')).toBeVisible();
    await expect(page.getByText('SQLite', { exact: true })).toBeVisible();
    await expect(
        page.getByRole('heading', { name: 'Database size, last 30 days (MB)' }),
    ).toBeVisible();
    const tables = page.getByRole('heading', { name: 'Tables', exact: true });
    await expect(async () => {
        await page.mouse.wheel(0, 2000);
        await expect(tables).toBeVisible({ timeout: 1000 });
    }).toPass();
    await expect(
        page.locator('tbody tr').filter({ hasText: 'deliveries' }).first(),
    ).toBeVisible();

    await page.getByRole('link', { name: 'Scheduler' }).first().click();
    await expect(page).toHaveURL(/\/admin\/scheduler$/);
    await expect(page.getByText('Never run here')).toBeVisible();
    await expect(
        page.getByText('No scheduled task has run on this server yet'),
    ).toBeVisible();

    expect(consoleErrors).toEqual([]);
});
