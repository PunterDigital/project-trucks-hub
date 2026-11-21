import { expect, test } from './fixtures';

// #4687: the live map. Seeded: Mona Map (on a job), Fred Free (free-roaming), Pia Private
// (private profile, must never show).
test('a guest sees live drivers as pins with truck and job, never private ones', async ({
    page,
}) => {
    await page.goto('/');
    await page.getByRole('link', { name: 'Live map' }).click();
    await expect(page).toHaveURL(/\/map$/);

    const map = page.locator('[data-test="live-map"]');
    await expect(map).toBeVisible();

    // The list under the map and the pins agree; Pia is in neither.
    const list = page.locator('[data-test="map-driver"]');
    await expect(list.filter({ hasText: 'Mona Map' })).toContainText(
        'Renault T · Hamburg → Wien',
    );
    await expect(list.filter({ hasText: 'Fred Free' })).toContainText(
        'Free-roaming',
    );
    await expect(list.filter({ hasText: 'Pia Private' })).toHaveCount(0);
    await expect(
        page.getByRole('button', { name: 'Mona Map', exact: true }),
    ).toBeVisible();
    await expect(
        page.getByRole('button', { name: 'Fred Free', exact: true }),
    ).toBeVisible();
    await expect(
        page.getByRole('button', { name: 'Pia Private', exact: true }),
    ).toHaveCount(0);

    // Clicking a pin shows the truck and the job, with a profile link.
    await page.getByRole('button', { name: 'Mona Map', exact: true }).click();
    const popup = page.locator('[data-test="map-popup"]');
    await expect(popup.locator('[data-test="map-popup-truck"]')).toHaveText(
        'Renault T',
    );
    await expect(popup.locator('[data-test="map-popup-job"]')).toHaveText(
        'Hamburg → Wien · Timber',
    );
    await popup.getByRole('link', { name: 'Mona Map' }).click();
    await expect(page).toHaveURL(/\/drivers\/mona-map$/);

    // The list's pin button opens the free-roaming driver's popup.
    await page.goto('/map');
    await page
        .getByRole('button', { name: 'Show Fred Free on the map' })
        .click();
    await expect(page.locator('[data-test="map-popup-job"]')).toHaveText(
        'Free-roaming',
    );
});

test('a driver can switch the live map off in their profile settings', async ({
    page,
}) => {
    await page.goto('/login');
    // Its own account: Fortify allows 5 logins a minute per email, and CI runs the specs fast.
    await page.getByLabel('Email address').fill('pia-private@example.com');
    await page.getByLabel('Password', { exact: true }).fill('password');
    await page.getByRole('button', { name: 'Log in' }).click();
    await expect(page).toHaveURL(/\/dashboard$/);

    await page.goto('/settings/profile');
    const toggle = page.locator('[data-test="show-on-map"]');
    await expect(toggle).toBeChecked();
    await toggle.uncheck();
    await page.locator('[data-test="update-profile-button"]').click();
    await expect(page.getByText('Profile updated.')).toBeVisible();

    await page.reload();
    await expect(page.locator('[data-test="show-on-map"]')).not.toBeChecked();
});

// #5207: tiles are rendered down to the closest zoom, and blank ones (sea, around the map)
// aren't stored, so the map must never ask for one. Any 404 also logs a console error.
test('the closest zoom loads its own tiles and never asks for a missing one', async ({
    page,
}) => {
    const tileStatus = new Map<string, number>();
    page.on('response', (response) => {
        const path = new URL(response.url()).pathname;
        if (path.startsWith('/map-tiles/ets2/tiles/')) {
            tileStatus.set(path, response.status());
        }
    });

    await page.goto('/map');
    await expect(page.locator('[data-test="live-map"]')).toHaveAttribute(
        'data-tiles',
        'yes',
    );

    // Fred Free is in Germany: roads all around at every zoom.
    await page
        .getByRole('button', { name: 'Show Fred Free on the map' })
        .click();
    await page.keyboard.press('Escape');
    const zoomIn = page.getByRole('button', { name: 'Zoom in' });
    while (
        !(await zoomIn.getAttribute('class'))?.includes('leaflet-disabled')
    ) {
        await zoomIn.click();
        await page.waitForTimeout(300);
    }

    await expect
        .poll(
            () =>
                [...tileStatus].filter(
                    ([path, status]) =>
                        path.includes('/tiles/8/') && status === 200,
                ).length,
        )
        .toBeGreaterThan(0);

    // All the way out too: most zoom-0..2 tiles around the map are blank.
    const zoomOut = page.getByRole('button', { name: 'Zoom out' });
    while (
        !(await zoomOut.getAttribute('class'))?.includes('leaflet-disabled')
    ) {
        await zoomOut.click();
        await page.waitForTimeout(150);
    }
    await page.waitForTimeout(500);

    const missing = [...tileStatus].filter(([, status]) => status >= 400);
    expect(missing).toEqual([]);
});
