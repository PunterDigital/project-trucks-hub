import type { Locator } from '@playwright/test';
import { expect, logIn, test } from './fixtures';

// Uploads (#4644): profile picture, company logo and banner.
// A tiny PNG; the server resizes every upload, so its size doesn't matter.
const png = {
    name: 'picture.png',
    mimeType: 'image/png',
    buffer: Buffer.from(
        'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg==',
        'base64',
    ),
};

/** The image really loaded from /media at the given size (not just an <img> tag). */
async function expectLoaded(img: Locator, width: number, height: number) {
    await expect(img).toHaveAttribute(
        'src',
        /^\/media\/(avatars|logos|banners)\/[A-Za-z0-9]{40}\.webp$/,
    );
    await expect
        .poll(() =>
            img.evaluate((el: HTMLImageElement) => [
                el.complete,
                el.naturalWidth,
                el.naturalHeight,
            ]),
        )
        .toEqual([true, width, height]);
}

test('a driver uploads and removes a profile picture', async ({ page }) => {
    await logIn(page, 'uma-upload@example.com');
    await page.goto('/settings/profile');

    const upload = page.locator('[data-test="avatar-upload"]');
    await expect(upload.getByText('None')).toBeVisible();
    await upload.locator('input[type="file"]').setInputFiles(png);
    await upload.locator('[data-test="avatar-submit"]').click();
    await expect(
        page.getByText('Profile picture updated.', { exact: true }),
    ).toBeVisible();
    await expectLoaded(
        upload.locator('[data-test="avatar-current"]'),
        256,
        256,
    );

    // The same picture on the public profile.
    await page.goto('/drivers/uma-upload');
    await expectLoaded(
        page.getByRole('img', { name: 'Uma Upload' }).first(),
        256,
        256,
    );

    await page.goto('/settings/profile');
    page.once('dialog', (d) => d.accept());
    await upload.locator('[data-test="avatar-remove"]').click();
    await expect(
        page.getByText('Profile picture removed.', { exact: true }),
    ).toBeVisible();
    await expect(upload.getByText('None')).toBeVisible();
});

test('an owner uploads a company logo and banner', async ({ page }) => {
    await logIn(page, 'uma-upload@example.com');
    await page.goto('/companies/picture-perfect-haulage/edit');

    for (const kind of ['logo', 'banner']) {
        const upload = page.locator(`[data-test="${kind}-upload"]`);
        await upload.locator('input[type="file"]').setInputFiles(png);
        await upload.locator(`[data-test="${kind}-submit"]`).click();
        await expect(
            upload.locator(`[data-test="${kind}-current"]`),
        ).toBeVisible();
    }
    await expectLoaded(page.locator('[data-test="logo-current"]'), 256, 256);
    await expectLoaded(page.locator('[data-test="banner-current"]'), 1500, 400);

    await page.goto('/companies/picture-perfect-haulage');
    await expectLoaded(page.locator('[data-test="company-banner"]'), 1500, 400);
    await expectLoaded(
        page.getByRole('img', { name: 'Picture Perfect Haulage logo' }),
        256,
        256,
    );

    // On the company cards too, for a guest.
    await page.context().clearCookies();
    await page.goto('/companies?search=Picture');
    await expectLoaded(
        page
            .locator('[data-test="company-card"]', {
                hasText: 'Picture Perfect Haulage',
            })
            .locator('[data-test="company-logo"]'),
        256,
        256,
    );

    // Removing the logo brings the tag back.
    await logIn(page, 'uma-upload@example.com');
    await page.goto('/companies/picture-perfect-haulage/edit');
    page.once('dialog', (d) => d.accept());
    await page.locator('[data-test="logo-remove"]').click();
    await expect(
        page.getByText('Logo removed.', { exact: true }),
    ).toBeVisible();
    await page.goto('/companies/picture-perfect-haulage');
    await expect(
        page.getByRole('img', { name: 'Picture Perfect Haulage logo' }),
    ).toHaveCount(0);
    await expect(page.locator('[data-test="company-banner"]')).toBeVisible();
});
