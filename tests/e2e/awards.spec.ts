import { readFile } from 'node:fs/promises';
import { expect, logIn, test } from './fixtures';

/**
 * Driver of the month and the member stats CSV (#5226). The seeder gives Trophy Haulage's
 * last-month awards: Ava Awards 1st, Abe Award (private profile) 2nd.
 */
const lastMonth = (() => {
    const now = new Date();
    const d = new Date(
        Date.UTC(now.getUTCFullYear(), now.getUTCMonth() - 1, 1),
    );

    return d.toLocaleString('en-GB', {
        month: 'long',
        year: 'numeric',
        timeZone: 'UTC',
    });
})();

test('awards show on the company page and the profile; managers download member stats', async ({
    page,
}) => {
    // A guest sees the awards, with the private driver's name hidden.
    await page.goto('/companies/trophy-haulage');
    const awards = page.locator('[data-test="company-awards"]');
    await expect(
        awards.locator('[data-test="award-month"]').first(),
    ).toContainText(lastMonth);
    const winners = awards.locator('[data-test="award-winner"]');
    await expect(winners.nth(0)).toContainText('1st');
    await expect(winners.nth(0)).toContainText('Ava Awards');
    await expect(winners.nth(1)).toContainText('2nd');
    await expect(winners.nth(1)).toContainText('Private driver');
    await expect(page.locator('[data-test="member-stats"]')).toHaveCount(0);

    // Ava's profile carries the badge, linking to the company.
    await page.goto('/drivers/ava-awards');
    const badge = page.locator('[data-test="driver-award"]');
    await expect(badge).toContainText('Driver of the month, 1st');
    await expect(badge).toContainText(lastMonth);
    await expect(badge.getByRole('link', { name: '[TRH]' })).toBeVisible();

    // The owner sees everyone's names and downloads all-time member stats.
    await logIn(page, 'ava-awards@example.com');
    await page.goto('/companies/trophy-haulage');
    await expect(
        page
            .locator('[data-test="company-awards"] [data-test="award-winner"]')
            .nth(1),
    ).toContainText('Abe Award');
    const stats = page.locator('[data-test="member-stats"]');
    await stats.getByLabel('Member stats').selectOption('all');
    const [download] = await Promise.all([
        page.waitForEvent('download'),
        stats.getByRole('link', { name: 'Download CSV' }).click(),
    ]);
    expect(download.suggestedFilename()).toBe(
        'trophy-haulage-member-stats-all.csv',
    );
    const csv = await readFile(await download.path(), 'utf8');
    const lines = csv.trimEnd().split('\r\n');
    expect(lines[0]).toBe(
        'Driver,Username,Role,Jobs,"Distance (km)","Income (EUR)","On time (%)","Last job (UTC)"',
    );
    expect(lines).toHaveLength(3);
    expect(lines[1]).toMatch(/^"Abe Award",abe-award,driver,1,150,6000,100,/);
    expect(lines[2]).toMatch(/^"Ava Awards",ava-awards,owner,1,180,7200,100,/);
});
