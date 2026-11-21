import { expect, job, logIn, test } from './fixtures';

// Company ranks (#5223): Rhonda owns Rank and File Haulage, Rob drives for it with no jobs.
// Rhonda sets up two ranks, Rob's app (a seeded token) syncs two jobs past the second one's
// distance, and both see the promotion. Then Rhonda pins a rank on Rob.

test('ranks follow the distance driven for the company and can be pinned', async ({
    page,
    browser,
    request,
}) => {
    await logIn(page, 'rhonda-ranks@example.com');
    await page.goto('/companies/rank-and-file-haulage/edit');
    const section = page.locator('#ranks');
    await expect(section).toContainText('No ranks');

    await section.getByRole('button', { name: 'Add a rank' }).click();
    await section.locator('#rank-name-0').fill('Rookie');
    await expect(section.locator('#rank-km-0')).toHaveValue('0');
    await section.getByRole('button', { name: 'Add a rank' }).click();
    await section.locator('#rank-name-1').fill('Road Captain');
    await section.locator('#rank-km-1').fill('500');
    await section.locator('#rank-color-1').fill('#0ea5e9');
    await section.getByRole('button', { name: 'Save ranks' }).click();
    await expect(page.getByText('Ranks saved.')).toBeVisible();
    await expect(section.locator('[data-test="rank-row"]')).toHaveCount(2);

    // A second save straight away keeps the same two ranks (the rows got their ids back).
    await section.getByRole('button', { name: 'Save ranks' }).click();
    await expect(section.locator('[data-test="rank-row"]')).toHaveCount(2);
    await expect(section.locator('#rank-name-1')).toHaveValue('Road Captain');

    const robRow = () =>
        page.locator('[data-test="member"]').filter({ hasText: 'Rob Rookie' });
    await page.goto('/companies/rank-and-file-haulage');
    await expect(robRow().locator('[data-test="rank"]')).toHaveText('Rookie');

    // Rob's app syncs two jobs (291.4 km each): 582.8 km, past Road Captain's 500. Ten days
    // old (before this week) and Rob's profile is private, so the public feed and the weekly
    // boards that other specs read stay as they were.
    const days = (n: number, hours = 0) =>
        new Date(Date.now() - n * 86_400_000 - hours * 3_600_000).toISOString();
    const synced = await request.post('/api/v1/jobs', {
        headers: {
            Authorization: 'Bearer e2e-rob-rookie-sync',
            Accept: 'application/json',
        },
        data: {
            jobs: [
                job({
                    source_city: 'Calais',
                    dest_city: 'Paris',
                    started_at: days(10, 5),
                    completed_at: days(10, 1),
                }),
                job({
                    source_city: 'Paris',
                    dest_city: 'Calais',
                    started_at: days(9, 5),
                    completed_at: days(9, 1),
                }),
            ],
        },
    });
    expect(synced.ok()).toBeTruthy();

    await page.reload();
    await expect(robRow().locator('[data-test="rank"]')).toHaveText(
        'Road Captain',
    );

    // Rob sees it on his profile and in the bell.
    const robContext = await browser.newContext();
    const rob = await robContext.newPage();
    await logIn(rob, 'rob-rookie@example.com');
    await rob.goto('/drivers/rob-rookie');
    await expect(rob.locator('[data-test="rank"]')).toHaveText('Road Captain');
    await rob.getByRole('button', { name: /^Notifications/ }).click();
    await expect(
        rob.getByText(
            'You were promoted to Road Captain at Rank and File Haulage.',
        ),
    ).toBeVisible();
    await robContext.close();

    // Pinning Rookie changes nothing (a pin is a floor), "By distance" is the default.
    const pin = robRow().getByRole('combobox', {
        name: 'Pinned rank of Rob Rookie',
    });
    await expect(pin).toHaveValue('');
    await pin.selectOption({ label: 'At least Rookie' });
    await expect(
        page.getByText('Rob Rookie is pinned at Rookie or above.'),
    ).toBeVisible();
    await expect(robRow().locator('[data-test="rank"]')).toHaveText(
        'Road Captain',
    );
    await expect(pin).toHaveValue(/^\d+$/);
});
