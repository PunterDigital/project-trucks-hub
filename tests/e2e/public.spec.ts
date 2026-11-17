import { expect, test } from './fixtures';

test('a guest browses the feed, drivers, companies and leaderboards', async ({
    page,
}) => {
    await page.goto('/');
    await expect(
        page.getByRole('heading', { name: 'Every delivery, shared.' }),
    ).toBeVisible();

    // The seeded delivery and the seeded driver on the road.
    await expect(page.locator('[data-test="job-row"]').first()).toContainText(
        'Oslo',
    );
    await expect(page.locator('[data-test="on-the-road"]')).toContainText(
        'Göteborg',
    );

    await page.getByRole('link', { name: 'Drivers', exact: true }).click();
    await expect(page).toHaveURL(/\/drivers$/);
    await expect(
        page.locator('[data-test="driver-card"]', { hasText: 'Olga Owner' }),
    ).toBeVisible();

    await page.getByRole('link', { name: 'Companies', exact: true }).click();
    await page
        .locator('[data-test="company-card"]', { hasText: 'Nordic Haulage' })
        .click();
    await expect(page).toHaveURL(/\/companies\/nordic-haulage$/);
    await expect(
        page.getByRole('link', { name: 'Log in to join' }),
    ).toBeVisible();
    await expect(
        page.locator('[data-test="member"]', { hasText: 'Olga Owner' }),
    ).toContainText('owner');

    await page.getByRole('link', { name: 'Leaderboards', exact: true }).click();
    await expect(
        page.locator('[data-test="leaderboard-row"]', {
            hasText: 'Olga Owner',
        }),
    ).toBeVisible();
    await page.getByRole('link', { name: 'Companies' }).last().click();
    await expect(page).toHaveURL(/board=companies/);
    await expect(
        page.locator('[data-test="leaderboard-row"]', {
            hasText: 'Nordic Haulage',
        }),
    ).toBeVisible();
});

test('registration asks for a username and lower-cases it', async ({
    page,
}) => {
    await page.goto('/register');
    await page.getByLabel('Name', { exact: true }).fill('Rita Register');
    await page.getByLabel('Username').fill('Rita-R');
    await page.getByLabel('Email address').fill('rita@example.com');
    await page.getByLabel('Password', { exact: true }).fill('password');
    await page.getByLabel('Confirm password').fill('password');
    await page.getByRole('button', { name: 'Create account' }).click();

    // New accounts verify their email before the dashboard.
    await expect(page).toHaveURL(/\/email\/verify$/);
    await page.goto('/drivers/rita-r');
    await expect(
        page.getByRole('heading', { name: 'Rita Register' }),
    ).toBeVisible();
});
