import { createHmac } from 'node:crypto';
import type { Page } from '@playwright/test';
import { expect, logIn, test } from './fixtures';

// #4664: the admin panel at /admin. Seeded staff: sam@example.com (2FA on).
const SAM_SECRET = 'JBSWY3DPEHPK3PXPJBSWY3DPEHPK3PXP';

/** RFC 6238 TOTP (SHA-1, 30 s, 6 digits), as an authenticator app computes it. */
function totp(base32: string, at = Date.now()): string {
    const alphabet = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';
    let bits = '';
    for (const char of base32.replace(/=+$/, '')) {
        bits += alphabet.indexOf(char).toString(2).padStart(5, '0');
    }
    const key = Buffer.from(
        bits.match(/.{8}/g)!.map((byte) => parseInt(byte, 2)),
    );
    const counter = Buffer.alloc(8);
    counter.writeBigUInt64BE(BigInt(Math.floor(at / 1000 / 30)));
    const hmac = createHmac('sha1', key).update(counter).digest();
    const offset = hmac[hmac.length - 1] & 0x0f;
    const code = (hmac.readUInt32BE(offset) & 0x7fffffff) % 1_000_000;

    return code.toString().padStart(6, '0');
}

async function logInAsStaff(page: Page): Promise<void> {
    await page.goto('/login');
    await page.getByLabel('Email address').fill('sam@example.com');
    await page.getByLabel('Password', { exact: true }).fill('password');
    await page.getByRole('button', { name: 'Log in' }).click();
    await expect(page).toHaveURL(/\/two-factor-challenge$/);
    await page.locator('#otp').click();
    await page.keyboard.type(totp(SAM_SECRET));
    await page.getByRole('button', { name: 'Continue' }).click();
    await expect(page).toHaveURL(/\/dashboard$/);
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

    // Users: search by name, open the driver, revoke (none linked, still audited).
    await page.getByRole('link', { name: 'Users' }).first().click();
    await expect(page).toHaveURL(/\/admin\/users$/);
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

    expect(consoleErrors).toEqual([]);
});
