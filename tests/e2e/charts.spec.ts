import { expect, test } from './fixtures';
import type { Page } from '@playwright/test';

// Stats charts (#4650): Cora's one job ended at 23:30 UTC six days ago, Cody's at 12:30 UTC.

async function daysWithDistance(page: Page): Promise<string[]> {
    const chart = page.locator('[data-test="distance-chart"]');
    await expect(chart.locator('[data-test="chart-day"]')).toHaveCount(30);

    return chart
        .locator('[data-test="chart-day"]:not([data-km="0"])')
        .evaluateAll((days) => days.map((d) => d.getAttribute('data-date')!));
}

function nextDay(key: string): string {
    const d = new Date(`${key}T12:00:00Z`);
    d.setUTCDate(d.getUTCDate() + 1);

    return d.toISOString().slice(0, 10);
}

test.describe('in UTC', () => {
    test.use({ timezoneId: 'UTC' });

    test('a profile charts distance per local day and cargo', async ({
        page,
    }) => {
        await page.goto('/drivers/cora-chart');
        const days = await daysWithDistance(page);
        expect(days).toHaveLength(1);
        await expect(
            page.locator(`[data-test="chart-day"][data-date="${days[0]}"]`),
        ).toHaveAttribute('data-km', '120');
        await expect(page.locator('[data-test="chart-total"]')).toHaveText(
            '120 km in 30 days',
        );
        await expect(
            page.getByRole('img', {
                name: /^Distance per day for the last 30 days, 120 km/,
            }),
        ).toBeVisible();

        const cargo = page.locator('[data-test="cargo-chart"] li');
        await expect(cargo).toHaveCount(1);
        await expect(cargo).toContainText('Chart paper');
        await expect(cargo).toContainText('1 job');
    });

    test('a company page stacks distance by driver', async ({ page }) => {
        await page.goto('/companies/chart-haulage');
        const legend = page.locator('[data-test="chart-legend"] li');
        await expect(legend).toHaveCount(2);
        await expect(legend.nth(0)).toContainText('Cora Chart');
        await expect(legend.nth(0)).toContainText('120 km');
        await expect(legend.nth(1)).toContainText('Cody Chart');
        // 12:30 and 23:30 UTC are the same UTC day.
        const days = await daysWithDistance(page);
        expect(days).toHaveLength(1);
        await expect(
            page.locator(`[data-test="chart-day"][data-date="${days[0]}"]`),
        ).toHaveAttribute('data-km', '200');
    });

    test('a driver with nothing in 30 days sees an empty chart', async ({
        page,
    }) => {
        await page.goto('/drivers/erin');
        expect(await daysWithDistance(page)).toEqual([]);
        await expect(
            page.getByText('No distance in the last 30 days.'),
        ).toBeVisible();
        await expect(page.getByText('No cargo yet.')).toBeVisible();
    });
});

test.describe('in Tokyo', () => {
    test.use({ timezoneId: 'Asia/Tokyo' });

    test('the same jobs fall on the viewer’s own days', async ({ page }) => {
        await page.goto('/companies/chart-haulage');
        const tokyo = await daysWithDistance(page);
        // 12:30 UTC is 21:30 in Tokyo, 23:30 UTC is 08:30 the next day.
        expect(tokyo).toHaveLength(2);
        expect(tokyo[1]).toBe(nextDay(tokyo[0]));
        await expect(
            page.locator(`[data-test="chart-day"][data-date="${tokyo[0]}"]`),
        ).toHaveAttribute('data-km', '80');
        await expect(
            page.locator(`[data-test="chart-day"][data-date="${tokyo[1]}"]`),
        ).toHaveAttribute('data-km', '120');
    });
});
