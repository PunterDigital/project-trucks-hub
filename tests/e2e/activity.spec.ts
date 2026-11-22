import { expect, logIn, test } from './fixtures';

// Monthly activity requirement (#5225): Ada sets a minimum on Active Haulage, sees each
// member's progress and Axel's "Under the minimum last month" badge; Axel sees only his own.
test('owners set a monthly minimum and see who is under it', async ({
    page,
    browser,
}) => {
    test.setTimeout(90_000);
    await logIn(page, 'ada-active@example.com');

    // No minimum yet: the members list shows nothing about it.
    await page.goto('/companies/active-haulage');
    await expect(page.locator('[data-test="activity-minimum"]')).toHaveCount(0);

    await page.goto('/companies/active-haulage/edit');
    const section = page.locator('#activity');
    await section.getByLabel('Distance per month (km)').fill('500');
    await section.getByLabel('Jobs per month').fill('4');
    await section.getByRole('button', { name: 'Save monthly minimum' }).click();
    await expect(page.getByText('Monthly minimum saved.')).toBeVisible();
    await expect(section.getByLabel('Distance per month (km)')).toHaveValue(
        '500',
    );

    await page.goto('/companies/active-haulage');
    await expect(page.locator('[data-test="activity-minimum"]')).toHaveText(
        'Monthly minimum: 500 km and 4 jobs',
    );
    const row = (p: typeof page, name: string) =>
        p.locator('[data-test="member"]').filter({ hasText: name });
    const axel = row(page, 'Axel Idle').locator(
        '[data-test="member-activity"]',
    );
    await expect(axel).toContainText('This month 0 km of 500 km, 0 of 4 jobs');
    await expect(axel).toContainText('no jobs yet');
    await expect(axel.locator('[data-test="under-minimum"]')).toHaveText(
        'Under the minimum last month',
    );
    // The owner's own row has progress too.
    await expect(
        row(page, 'Ada Active').locator('[data-test="member-activity"]'),
    ).toBeVisible();

    // Axel sees the minimum and his own progress, not Ada's.
    const axelContext = await browser.newContext();
    const axelPage = await axelContext.newPage();
    await logIn(axelPage, 'axel-idle@example.com');
    await axelPage.goto('/companies/active-haulage');
    await expect(
        axelPage.locator('[data-test="activity-minimum"]'),
    ).toBeVisible();
    await expect(
        row(axelPage, 'Axel Idle').locator('[data-test="member-activity"]'),
    ).toContainText('This month 0 km of 500 km');
    await expect(
        row(axelPage, 'Ada Active').locator('[data-test="member-activity"]'),
    ).toHaveCount(0);
    await axelContext.close();

    // Clearing both boxes removes the minimum again.
    await page.goto('/companies/active-haulage/edit');
    await section.getByLabel('Distance per month (km)').fill('');
    await section.getByLabel('Jobs per month').fill('');
    await section.getByRole('button', { name: 'Save monthly minimum' }).click();
    await expect(page.getByText('Monthly minimum saved.')).toBeVisible();
    await page.goto('/companies/active-haulage');
    await expect(page.locator('[data-test="activity-minimum"]')).toHaveCount(0);
    // Without a minimum, owners still see the last job date.
    await expect(
        row(page, 'Axel Idle').locator('[data-test="member-activity"]'),
    ).toContainText('no jobs yet');
});
