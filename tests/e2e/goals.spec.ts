import { expect, logIn, test } from './fixtures';

// Company goals (#4649): Gina (owner of Goal Getters) sets this month's targets and sees the
// progress bars, each member's share and last month's hit/missed row. Guests see the goals
// but no edit button, and the private drivers' names are masked.
test('an owner sets monthly goals and sees progress', async ({
    page,
    browser,
}) => {
    await logIn(page, 'gina-goals@example.com');
    await page.goto('/companies/goal-getters');

    const panel = page.locator('[data-test="company-goals"]');
    await expect(panel).toBeVisible();
    await expect(panel.getByText('No goals set for this month.')).toBeVisible();

    // Last month: 40 km of a 30 km goal (hit), 1 of 5 deliveries (missed).
    const history = panel.locator('[data-test="goal-month"]');
    await expect(history).toHaveCount(1);
    await expect(history.locator('[data-hit="yes"]')).toContainText('Distance');
    await expect(history.locator('[data-hit="no"]')).toContainText(
        'Deliveries',
    );

    await panel.locator('[data-test="edit-goals"]').click();
    await panel.getByLabel('Distance (km)').fill('100');
    await panel.getByLabel('Deliveries (jobs)').fill('2');
    await panel.locator('[data-test="save-goals"]').click();
    await expect(page.getByText('Goals saved for this month.')).toBeVisible();

    // 60 of 100 km and 2 of 2 deliveries this month.
    const distance = panel.locator(
        '[data-test="goal"][data-metric="distance_km"]',
    );
    await expect(distance.locator('[data-test="goal-percent"]')).toHaveText(
        '60%',
    );
    await expect(
        distance.getByRole('progressbar', { name: 'Distance goal' }),
    ).toHaveAttribute('aria-valuenow', '60');
    await expect(distance.locator('[data-test="contributor"]')).toHaveText([
        /Gina Goals\s+40 km \(40%\)/,
        /Gus Goals\s+20 km \(20%\)/,
    ]);
    const deliveries = panel.locator(
        '[data-test="goal"][data-metric="deliveries"]',
    );
    await expect(deliveries.locator('[data-test="goal-percent"]')).toHaveText(
        '100%',
    );
    await expect(
        panel.locator('[data-test="goal"][data-metric="income"]'),
    ).toHaveCount(0);

    // Edit again: the form keeps the saved targets; clearing one removes that goal.
    await panel.locator('[data-test="edit-goals"]').click();
    await expect(panel.getByLabel('Distance (km)')).toHaveValue('100');
    await panel.getByLabel('Deliveries (jobs)').fill('');
    await panel.locator('[data-test="save-goals"]').click();
    await expect(panel.locator('[data-test="goal"]')).toHaveCount(1);

    // A guest sees the goal, masked names and no edit button.
    const guest = await browser.newContext();
    const guestPage = await guest.newPage();
    await guestPage.goto('/companies/goal-getters');
    const guestPanel = guestPage.locator('[data-test="company-goals"]');
    await expect(guestPanel.locator('[data-test="goal-percent"]')).toHaveText(
        '60%',
    );
    await expect(guestPanel.locator('[data-test="contributor"]')).toHaveText([
        /Private driver\s+40 km/,
        /Private driver\s+20 km/,
    ]);
    await expect(guestPanel.locator('[data-test="edit-goals"]')).toHaveCount(0);
    await guest.close();
});
