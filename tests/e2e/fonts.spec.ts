import { expect, test } from './fixtures';

// #5733: Instrument Sans comes from our own origin (public/fonts/instrument-sans), not
// fonts.bunny.net, and the page really renders in it, light and dark.
for (const colorScheme of ['light', 'dark'] as const) {
    test.describe(`with a ${colorScheme} system`, () => {
        test.use({ colorScheme });

        test('text renders in Instrument Sans with no third-party font request', async ({
            page,
        }) => {
            // Font and stylesheet requests: only our own origin.
            const hosts = new Set<string>();
            page.on('request', (r) => {
                if (['font', 'stylesheet'].includes(r.resourceType())) {
                    hosts.add(new URL(r.url()).host);
                }
            });

            await page.goto('/');
            const fonts = await page.evaluate(async () => {
                await document.fonts.ready;

                return {
                    loaded: [...document.fonts]
                        .filter((f) => f.status === 'loaded')
                        .map(
                            (f) => `${f.family.replace(/"/g, '')} ${f.weight}`,
                        ),
                    body: getComputedStyle(document.body).fontFamily,
                    dark: document.documentElement.classList.contains('dark'),
                };
            });

            expect(fonts.body).toMatch(/^"?Instrument Sans/);
            expect(fonts.loaded).toContain('Instrument Sans 400');
            expect(fonts.dark).toBe(colorScheme === 'dark');
            expect([...hosts]).toEqual([new URL(page.url()).host]);

            const file = await page.request.get(
                '/fonts/instrument-sans/instrument-sans-latin-400-normal.woff2',
            );
            expect(file.status()).toBe(200);
        });
    });
}
