import { expect, test } from './fixtures';

test('the download page links straight to the installer', async ({
    page,
    request,
}) => {
    await page.goto('/');
    await page.getByRole('link', { name: 'Get ProjectTrucks' }).first().click();
    await expect(page).toHaveURL(/\/download$/);
    await expect(
        page.getByRole('heading', { name: 'Download ProjectTrucks' }),
    ).toBeVisible();
    await expect(page.locator('[data-test="release-version"]')).toContainText(
        'Version 0.7.0',
    );
    await expect(page.locator('[data-test="sha256"]').first()).toContainText(
        '19915b3a',
    );

    const exe = page.locator('[data-test="download-exe"]');
    await expect(exe).toHaveAttribute('href', '/download/windows');
    await expect(page.locator('[data-test="download-msi"]')).toHaveAttribute(
        'href',
        '/download/windows/msi',
    );

    // The link answers with a redirect to the file on GitHub (not followed:
    // the test never downloads from GitHub).
    const res = await request.get('/download/windows', { maxRedirects: 0 });
    expect(res.status()).toBe(302);
    expect(res.headers()['location']).toBe(
        'https://github.com/PunterDigital/project-trucks/releases/download/v0.7.0/ProjectTrucks_0.7.0_x64-setup.exe',
    );
});
