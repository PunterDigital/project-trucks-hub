import { expect, test } from './fixtures';

// #4661: the reset-password mail renders with the Hub mail theme inside a real request
// (the array mailer keeps it). A broken mail view would answer 500 here, not a status line.
test('a driver asks for a reset link from the login page', async ({ page }) => {
    await page.goto('/login');
    await page.getByRole('link', { name: 'Forgot your password?' }).click();
    await expect(page).toHaveURL(/\/forgot-password$/);

    await page.getByLabel('Email address').fill('olga@example.com');
    await page
        .locator('[data-test="email-password-reset-link-button"]')
        .click();

    await expect(
        page.getByText('We have emailed your password reset link.'),
    ).toBeVisible();
});

// #5532: an address with no account gets the same answer.
test('forgot password does not say whether an address has an account', async ({
    page,
}) => {
    await page.goto('/forgot-password');
    await page.getByLabel('Email address').fill('nobody-here@example.com');
    await page
        .locator('[data-test="email-password-reset-link-button"]')
        .click();

    await expect(
        page.getByText('We have emailed your password reset link.'),
    ).toBeVisible();
    await expect(page.getByText(/can't find a user/)).toHaveCount(0);
});
