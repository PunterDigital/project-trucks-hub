import type { Page } from '@playwright/test';
import { expect, logIn, test } from './fixtures';

// #4658: Light / Dark from the user menu, Light / Dark / System in Settings → Appearance,
// and every page really dark (tokens, native controls) once the class is on <html>.

const html = (page: Page) => page.locator('html');

/** What the browser actually paints: the page background and the native-control scheme. */
async function painted(page: Page) {
    return page.evaluate(() => {
        // Any CSS colour → [r, g, b] through a 1×1 canvas (computed values may be oklch()).
        const ctx = document.createElement('canvas').getContext('2d')!;
        ctx.fillStyle = getComputedStyle(document.body).backgroundColor;
        ctx.fillRect(0, 0, 1, 1);
        const [r, g, b] = ctx.getImageData(0, 0, 1, 1).data;

        return {
            background: (r + g + b) / 3,
            scheme: getComputedStyle(document.documentElement).colorScheme,
        };
    });
}

async function openUserMenu(page: Page) {
    await page.locator('[data-test="sidebar-menu-button"]').click();

    return page.locator('[data-test="theme-toggle"]');
}

test.describe('with a light system', () => {
    test.use({ colorScheme: 'light' });

    test('the user menu switches between light and dark, and it sticks', async ({
        page,
    }) => {
        await logIn(page, 'tina-theme@example.com');
        await expect(html(page)).not.toHaveClass(/dark/);
        expect((await painted(page)).scheme).toBe('light');

        const toggle = await openUserMenu(page);
        await expect(toggle).toHaveText('Dark mode');
        await toggle.click();
        await expect(html(page)).toHaveClass(/dark/);
        // The menu stays open and now offers the way back.
        await expect(toggle).toHaveText('Light mode');
        const dark = await painted(page);
        expect(dark.scheme).toBe('dark');
        expect(dark.background).toBeLessThan(60);

        // The server renders the class from the cookie: dark before any script runs.
        await page.keyboard.press('Escape');
        const response = await page.request.get('/dashboard');
        expect(await response.text()).toMatch(/<html[^>]*class="[^"]*dark/);

        // Every page keeps it, public and signed-in.
        for (const url of [
            '/',
            '/drivers',
            '/companies',
            '/leaderboards',
            '/events',
            '/map',
            '/download',
            '/settings/profile',
        ]) {
            await page.goto(url);
            await expect(html(page)).toHaveClass(/dark/);
            expect((await painted(page)).background).toBeLessThan(60);
        }

        await page.goto('/dashboard');
        const back = await openUserMenu(page);
        await expect(back).toHaveText('Light mode');
        await back.click();
        await expect(html(page)).not.toHaveClass(/dark/);
        expect((await painted(page)).background).toBeGreaterThan(200);
        await page.keyboard.press('Escape');
        await page.reload();
        await expect(html(page)).not.toHaveClass(/dark/);
    });
});

test.describe('with a dark system', () => {
    test.use({ colorScheme: 'dark' });

    test('Settings → Appearance offers Light, Dark and System', async ({
        page,
    }) => {
        await logIn(page, 'tina-theme@example.com');
        // Nothing chosen yet in this browser: System, which is dark here.
        await expect(html(page)).toHaveClass(/dark/);

        await page.goto('/settings/appearance');
        await page.getByRole('button', { name: 'Light' }).click();
        await expect(html(page)).not.toHaveClass(/dark/);
        await page.reload();
        await expect(html(page)).not.toHaveClass(/dark/);

        await page.getByRole('button', { name: 'Dark' }).click();
        await expect(html(page)).toHaveClass(/dark/);

        await page.getByRole('button', { name: 'Light' }).click();
        await page.getByRole('button', { name: 'System' }).click();
        await expect(html(page)).toHaveClass(/dark/);
        await page.emulateMedia({ colorScheme: 'light' });
        await expect(html(page)).not.toHaveClass(/dark/);
    });
});
