import { expect, logIn, test } from './fixtures';

// Runs on the SSR server (Playwright project `ssr`, #5157): Laravel with
// INERTIA_SSR_ENABLED=true talking to the built bootstrap/ssr/app.js. Every public page must
// arrive with its text in the HTML (crawlers that don't run JS read only that), keep one
// title, and hydrate without console errors (the fixture fails the test on any).
const pages = [
    { path: '/', text: 'Every delivery, shared.' },
    { path: '/leaderboards', text: 'Leaderboards' },
    { path: '/map', text: 'Live map' },
    { path: '/download', text: 'Download ProjectTrucks' },
    { path: '/drivers', text: 'Drivers' },
    { path: '/drivers/olga', text: 'Olga Owner' },
    { path: '/companies', text: 'Companies' },
    { path: '/companies/nordic-haulage', text: 'Nordic Haulage' },
    { path: '/events', text: 'Upcoming events' },
    { path: '/blog', text: 'Welcome to the ProjectTrucks blog' },
    { path: '/blog/welcome', text: 'convoy news' },
    { path: '/guides', text: 'How the ETS2 leaderboards work' },
    { path: '/guides/ets2-telemetry-plugin', text: 'Remove it' },
    { path: '/privacy', text: 'Privacy' },
    { path: '/terms', text: 'Terms of use' },
    { path: '/login', text: 'Log in' },
];

// What the server put inside #app, before any JS runs (the page props are a separate
// `<script data-page="app" type="application/json">`).
function appHtml(html: string): string {
    const match = html.match(
        /<div data-server-rendered="true" id="app">([\s\S]*)<\/div>\s*<\/body>/,
    );
    expect(match, 'the page has a server-rendered #app').not.toBeNull();

    return match![1];
}

for (const { path, text } of pages) {
    test(`${path} arrives rendered and hydrates without errors`, async ({
        page,
    }) => {
        const response = await page.request.get(path);
        expect(response.status()).toBe(200);
        const html = await response.text();

        const app = appHtml(html);
        expect(app).toContain(text);
        expect(app).not.toContain('[object Promise]');

        const titles = html.match(/<title[^>]*>[^<]*<\/title>/g) ?? [];
        expect(titles, 'one title').toHaveLength(1);

        await page.goto(path);
        await expect(page.getByText(text).first()).toBeVisible();
    });
}

test('signed-in pages render on the server and hydrate without errors', async ({
    page,
}) => {
    await logIn(page, 'olga@example.com');

    for (const { path, text } of [
        { path: '/dashboard', text: 'Olga Owner' },
        { path: '/settings/profile', text: 'Profile' },
        { path: '/settings/appearance', text: 'Appearance' },
    ]) {
        const response = await page.request.get(path);
        expect(response.status(), path).toBe(200);
        expect(appHtml(await response.text()), path).toContain(text);

        await page.goto(path);
        await expect(page.getByText(text).first(), path).toBeVisible();
    }
});
