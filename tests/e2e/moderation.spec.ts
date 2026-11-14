import type { Page } from '@playwright/test';
import { expect, logIn, test } from './fixtures';
import { logInWithTotp } from './totp';

// #4651: drivers report, staff work the Reports queue and suspend accounts.
// Seeded: rex-report (reporter), troll-tom (reported), susie-suspend, mo-mod (staff, 2FA).
const MO_SECRET = 'MFRGGZDFMZTWQ2LKNNWG23TPOBYXE43U';

/** Opens a Filament modal; a click before Livewire has loaded opens nothing, so retry. */
async function openModal(page: Page, button: string, heading: string) {
    await expect(async () => {
        await page.getByRole('button', { name: button, exact: true }).click();
        await expect(page.getByRole('heading', { name: heading })).toBeVisible({
            timeout: 2000,
        });
    }).toPass();
}

test('a driver reports a profile and staff resolve the report', async ({
    page,
    browser,
}) => {
    await logIn(page, 'rex-report@example.com');
    await page.goto('/drivers/troll-tom');
    await expect(
        page.getByRole('heading', { name: 'Troll Tom' }),
    ).toBeVisible();

    const send = async () => {
        await page.locator('[data-test="report-button"]').click();
        const dialog = page.getByRole('dialog');
        await expect(
            dialog.getByRole('heading', { name: 'Report this driver' }),
        ).toBeVisible();
        await dialog.getByLabel('Offensive name or text').check();
        await dialog.getByLabel('Details (optional)').fill('Rude name');
        await dialog.locator('[data-test="report-submit"]').click();
        await expect(dialog).toBeHidden();
    };

    await send();
    await expect(page.getByText('Thanks for the report.')).toBeVisible();
    await send();
    await expect(page.getByText('You’ve already reported this.')).toBeVisible();

    // No Report button on your own profile.
    await page.goto('/drivers/rex-report');
    await expect(
        page.getByRole('heading', { name: 'Rex Reporter' }),
    ).toBeVisible();
    await expect(page.locator('[data-test="report-button"]')).toHaveCount(0);

    // Staff, in their own browser.
    const context = await browser.newContext();
    const staff = await context.newPage();
    await logInWithTotp(staff, 'mo-mod@example.com', MO_SECRET);
    await staff.goto('/admin/reports');
    const row = staff.locator('.fi-ta-row').filter({ hasText: 'Troll Tom' });
    await expect(row).toHaveCount(1);
    await expect(row).toContainText('Offensive name or text');
    await expect(row).toContainText('Rex Reporter');
    await row.getByRole('link').first().click();
    await expect(staff).toHaveURL(/\/admin\/reports\/\d+$/);
    await expect(staff.getByText('Rude name')).toBeVisible();

    await openModal(staff, 'Resolve', 'Resolve this report?');
    await staff
        .getByRole('dialog')
        .getByLabel('Note')
        .fill('Asked Tom to rename');
    await staff
        .getByRole('dialog')
        .getByRole('button', { name: 'Resolve' })
        .click();
    await expect(staff.getByText('Report resolved')).toBeVisible();
    await expect(staff.getByText('Asked Tom to rename')).toBeVisible();

    await staff.goto('/admin/reports');
    await expect(
        staff.locator('.fi-ta-row').filter({ hasText: 'Troll Tom' }),
    ).toHaveCount(0);
    await context.close();
});

test('staff suspend a driver: profile gone, sign-in refused, then lifted', async ({
    browser,
}) => {
    // Mo signed in in the test above: the second sign-in waits for a fresh TOTP code (≤ 30 s).
    test.setTimeout(90_000);
    const context = await browser.newContext();
    const staff = await context.newPage();
    await logInWithTotp(staff, 'mo-mod@example.com', MO_SECRET);
    await staff.goto('/admin/users');
    await staff
        .getByRole('link', { name: /^Susie Suspend/ })
        .first()
        .click();
    await expect(staff).toHaveURL(/\/admin\/users\/\d+$/);

    await openModal(staff, 'Suspend', 'Suspend this account?');
    await staff
        .getByRole('dialog')
        .getByRole('textbox', { name: /^Reason/ })
        .fill('Spam in profile');
    await staff
        .getByRole('dialog')
        .getByRole('button', { name: 'Suspend' })
        .click();
    await expect(staff.getByText('Account suspended')).toBeVisible();
    await expect(
        staff.getByRole('button', { name: 'Lift suspension' }),
    ).toBeVisible();

    // A guest (separate browser, so its expected 404 isn't a console error in the test page).
    const guestContext = await browser.newContext();
    const guest = await guestContext.newPage();
    const profile = await guest.goto('/drivers/susie-suspend');
    expect(profile?.status()).toBe(404);

    // Susie tries to sign in: she lands back on the login page with the reason.
    await guest.goto('/login');
    await guest.getByLabel('Email address').fill('susie-suspend@example.com');
    await guest.getByLabel('Password', { exact: true }).fill('password');
    await guest.getByRole('button', { name: 'Log in' }).click();
    await expect(guest).toHaveURL(/\/login$/);
    await expect(guest.getByText('This account is suspended.')).toBeVisible();

    await openModal(staff, 'Lift suspension', 'Lift the suspension?');
    await staff
        .getByRole('dialog')
        .getByRole('textbox', { name: /^Reason/ })
        .fill('Appeal accepted');
    await staff
        .getByRole('dialog')
        .getByRole('button', { name: 'Lift suspension' })
        .click();
    await expect(staff.getByText('Suspension lifted')).toBeVisible();

    const back = await guest.goto('/drivers/susie-suspend');
    expect(back?.status()).toBe(200);

    await guestContext.close();
    await context.close();
});
