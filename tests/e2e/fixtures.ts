import { expect, test as base } from '@playwright/test';
import type { Page } from '@playwright/test';

/** Every test fails on a console error or an uncaught page error. */
export const test = base.extend<{ consoleErrors: string[] }>({
    consoleErrors: [
        async ({ page }, use) => {
            const errors: string[] = [];
            page.on('console', (msg) => {
                if (msg.type() === 'error') {
                    errors.push(msg.text());
                }
            });
            page.on('pageerror', (err) => errors.push(err.message));
            await use(errors);
            expect(errors, 'console errors').toEqual([]);
        },
        { auto: true },
    ],
});

export { expect };

export async function logIn(page: Page, email: string): Promise<void> {
    await page.goto('/login');
    await page.getByLabel('Email address').fill(email);
    await page.getByLabel('Password', { exact: true }).fill('password');
    await page.getByRole('button', { name: 'Log in' }).click();
    await expect(page).toHaveURL(/\/dashboard$/);
}

/** A row as ProjectTrucks stores it in its own `jobs` table. */
export function job(overrides: Record<string, unknown> = {}) {
    return {
        id: crypto.randomUUID(),
        truck_make: 'Scania',
        truck_model: 'S',
        source_city: 'Calais',
        source_company: 'Tradeaux',
        dest_city: 'Paris',
        dest_company: 'Euroacres',
        cargo_id: 'apples',
        cargo_name: 'Apples',
        cargo_mass_kg: 18000,
        declared_income: 12450,
        distance_km: 291.4,
        duration_sec: 14400,
        avg_speed_kmh: 72.8,
        fuel_used_l: 95.2,
        damage_delta: 0.013,
        started_at: new Date(Date.now() - 4 * 3600_000).toISOString(),
        completed_at: new Date(Date.now() - 60_000).toISOString(),
        status: 'DELIVERED',
        source: 'TELEMETRY',
        flagged: false,
        flag_reason: null,
        ...overrides,
    };
}
