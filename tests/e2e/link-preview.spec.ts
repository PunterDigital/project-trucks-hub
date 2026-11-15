import { expect, test } from './fixtures';

// Link previews (#4652): the tags a Discord crawler reads, and the card they point at.
test('a company page carries Open Graph tags and its preview card loads', async ({
    page,
}) => {
    await page.goto('/companies/nordic-haulage');

    const meta = (key: string) =>
        page
            .locator(`meta[property="${key}"], meta[name="${key}"]`)
            .first()
            .getAttribute('content');

    expect(await meta('og:title')).toMatch(/^Nordic Haulage \[/);
    expect(await meta('og:url')).toMatch(/\/companies\/nordic-haulage$/);
    expect(await meta('twitter:card')).toBe('summary_large_image');
    const image = await meta('og:image');
    expect(image).toMatch(/\/og\/company\/\d+\.png\?v=[0-9a-f]{16}$/);

    // The card itself: a 1200×630 PNG, served without a session cookie.
    const url = new URL(image!);
    const response = await page.request.get(url.pathname + url.search);
    expect(response.status()).toBe(200);
    expect(response.headers()['content-type']).toBe('image/png');
    expect(response.headers()['set-cookie']).toBeUndefined();

    await page.goto(url.pathname + url.search);
    const size = await page
        .locator('img')
        .evaluate((img: HTMLImageElement) => [
            img.naturalWidth,
            img.naturalHeight,
        ]);
    expect(size).toEqual([1200, 630]);
});

test('a job page links its own card, and a private driver gets none', async ({
    page,
}) => {
    await page.goto('/drivers/jack-job');
    await page
        .locator('[data-test="job-row"]', { hasText: 'Lyon' })
        .getByRole('link', { name: /Rotterdam/ })
        .click();
    await expect(page).toHaveURL(/\/jobs\/\d+$/);

    // Client-side visits don't change <head>: the crawler always gets a full page load.
    await page.reload();
    await expect(page.locator('meta[property="og:title"]')).toHaveAttribute(
        'content',
        'Rotterdam → Lyon',
    );

    await page.goto('/drivers/pia-private');
    await expect(page.locator('meta[property="og:title"]')).toHaveCount(0);
    await expect(page.locator('meta[property="og:site_name"]')).toHaveCount(1);
});
