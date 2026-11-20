import { expect, test } from './fixtures';

// Search tags (#5149): what a crawler reads in the server HTML, and that the tab title the
// app sets after loading is the same one.
const pages = [
    '/',
    '/leaderboards',
    '/map',
    '/download',
    '/drivers',
    '/companies',
    '/events',
    '/privacy',
    '/terms',
    '/drivers/olga',
    '/companies/nordic-haulage',
    '/blog',
    '/blog/welcome',
];

function tag(html: string, pattern: RegExp): string | null {
    const match = html.match(pattern);

    return match
        ? match[1].replace(/&amp;/g, '&').replace(/&#039;/g, "'")
        : null;
}

test('public pages send a title, description and canonical that match the app', async ({
    page,
}) => {
    const titles = new Set<string>();

    for (const path of pages) {
        const response = await page.request.get(path);
        expect(response.status(), path).toBe(200);
        const html = await response.text();

        const title = tag(html, /<title[^>]*>([^<]*)<\/title>/);
        expect(title, path).toMatch(/ - .+$/);
        expect(
            tag(html, /<meta name="description" content="([^"]+)"/),
            path,
        ).not.toBeNull();
        expect(tag(html, /<link rel="canonical" href="([^"]+)"/), path).toMatch(
            new RegExp(`^https?://[^/]+${path === '/' ? '/' : path}$`),
        );
        expect(html, path).not.toContain('name="robots"');
        titles.add(title!);

        // The tab title once the app has loaded is the one the server sent.
        await page.goto(path);
        await expect(page, path).toHaveTitle(title!);
    }

    expect(titles.size).toBe(pages.length);
});

test('sign-in pages and private profiles are noindex', async ({ page }) => {
    for (const path of ['/login', '/register', '/drivers/pia-private']) {
        const html = await (await page.request.get(path)).text();
        expect(html, path).toContain('<meta name="robots" content="noindex">');
        expect(html, path).not.toContain('rel="canonical"');
    }
});

test('the download page describes the app as structured data', async ({
    page,
}) => {
    await page.goto('/download');
    const json = await page
        .locator('script[type="application/ld+json"]')
        .textContent();
    const app = JSON.parse(json!);

    expect(app['@type']).toBe('SoftwareApplication');
    expect(app.name).toBe('ProjectTrucks');
    expect(app.operatingSystem).toBe('Windows');
});

test('sitemap.xml lists public drivers and companies, robots.txt points at it', async ({
    page,
}) => {
    const response = await page.request.get('/sitemap.xml');
    expect(response.status()).toBe(200);
    expect(response.headers()['content-type']).toContain('application/xml');
    expect(response.headers()['set-cookie']).toBeUndefined();
    const xml = await response.text();

    expect(xml).toContain('/drivers/olga</loc>');
    expect(xml).toContain('/companies/nordic-haulage</loc>');
    expect(xml).not.toContain('/drivers/pia-private<');

    const robots = await (await page.request.get('/robots.txt')).text();
    expect(robots).toMatch(/^Sitemap: https:\/\/.+\/sitemap\.xml$/m);
    expect(robots).toContain('Disallow: /settings');
});
