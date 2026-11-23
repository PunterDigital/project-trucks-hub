import { expect, test } from './fixtures';

// Security headers from the app (#5533), as the real server sends them, and nothing else
// on the site can be framed.
test('pages and API answers carry the security headers and refuse framing', async ({
    page,
    request,
}) => {
    for (const path of ['/', '/login', '/sitemap.xml', '/no-such-page']) {
        const res = await request.get(path);
        const headers = res.headers();
        expect(headers['strict-transport-security'], path).toBe(
            'max-age=31536000',
        );
        expect(headers['content-security-policy'], path).toContain(
            "frame-ancestors 'none'",
        );
        expect(headers['referrer-policy'], path).toBe(
            'strict-origin-when-cross-origin',
        );
        expect(headers['x-content-type-options'], path).toBe('nosniff');
        expect(headers['permissions-policy'], path).toContain('camera=()');
        expect(headers['x-frame-options'], path).toBeUndefined();
    }

    const api = await request.get('/api/v1/me', {
        headers: { Accept: 'application/json' },
    });
    expect(api.status()).toBe(401);
    expect(api.headers()['strict-transport-security']).toBe('max-age=31536000');

    // The home page still loads with no console errors (the fixture checks).
    await page.goto('/');
    await expect(page.locator('#app')).not.toBeEmpty();
});
