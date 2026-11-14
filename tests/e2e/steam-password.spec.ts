import { expect, logIn, test } from './fixtures';

// #4672: a Steam sign-up never chose a password, so no screen may ask for one.
test('a Steam-only driver is never asked for a password they never set', async ({
    page,
}) => {
    await logIn(page, 'steve@example.com');

    // Settings → Security first asks to confirm it's you: no password field, a note instead.
    await page.goto('/settings/security');
    await expect(page).toHaveURL(/\/user\/confirm-password$/);
    await expect(page.locator('[data-test="no-password-note"]')).toBeVisible();
    await expect(page.getByLabel('Password', { exact: true })).toHaveCount(0);
    await expect(
        page.locator('[data-test="confirm-password-button"]'),
    ).toHaveCount(0);

    // Delete account: no password field, the dialog explains the Steam confirmation.
    await page.goto('/settings/profile');
    await page.locator('[data-test="delete-user-button"]').click();
    const dialog = page.getByRole('dialog');
    await expect(dialog).toContainText('You signed up with Steam');
    await expect(dialog.getByPlaceholder('Password')).toHaveCount(0);

    // Submitting without a recent confirmation goes to the confirm page, account intact.
    await dialog.locator('[data-test="confirm-delete-user-button"]').click();
    await expect(page).toHaveURL(/\/user\/confirm-password$/);
});

test('a password driver still confirms with their password', async ({
    page,
}) => {
    await logIn(page, 'erin@example.com');

    await page.goto('/settings/security');
    await expect(page).toHaveURL(/\/user\/confirm-password$/);
    await page.getByLabel('Password', { exact: true }).fill('password');
    await page.locator('[data-test="confirm-password-button"]').click();
    await expect(page).toHaveURL(/\/settings\/security$/);
    await expect(
        page.getByRole('heading', { name: 'Update password' }),
    ).toBeVisible();
    await expect(page.getByLabel('Current password')).toBeVisible();
});
