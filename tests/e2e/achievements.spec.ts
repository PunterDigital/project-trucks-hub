import { expect, test } from './fixtures';

// Levels and achievements (#4645). Olga: one seeded 530 km delivery, owner of Nordic Haulage.
test('a guest sees a driver’s level, rank and achievements', async ({
    page,
}) => {
    await page.goto('/drivers/olga');
    await expect(page.locator('[data-test="driver-level"]')).toContainText(
        'Level 1 · Rookie',
    );
    await expect(page.getByRole('progressbar')).toHaveAccessibleName(
        /530 km of 1,000 km to level 2/,
    );

    // Jobs first; the Achievements tab lists every achievement.
    await expect(page.getByRole('tab', { name: 'Jobs' })).toHaveAttribute(
        'aria-selected',
        'true',
    );
    await page.getByRole('tab', { name: /^Achievements \d+\/12$/ }).click();
    await expect(
        page.locator('[data-test="achievement-first_delivery"]'),
    ).toHaveAttribute('data-unlocked', 'true');
    await expect(
        page.locator('[data-test="achievement-first_delivery"]'),
    ).toContainText('Unlocked');
    await expect(
        page.locator('[data-test="achievement-joined_company"]'),
    ).toHaveAttribute('data-unlocked', 'true');
    await expect(
        page.locator('[data-test="achievement-distance_1k"]'),
    ).toHaveAttribute('data-unlocked', 'false');
    await expect(page.locator('[data-test^="achievement-"]')).toHaveCount(12);

    // A link to #achievements (the bell's) opens that tab straight away.
    await page.goto('/drivers/olga#achievements');
    await expect(
        page.getByRole('tab', { name: /^Achievements/ }),
    ).toHaveAttribute('aria-selected', 'true');

    // The level next to her name on the leaderboard.
    await page.goto('/leaderboards?period=all');
    await expect(
        page
            .locator('[data-test="leaderboard-row"]', { hasText: 'Olga Owner' })
            .locator('[data-test="driver-level-badge"]'),
    ).toHaveText('Lv 1');
});
