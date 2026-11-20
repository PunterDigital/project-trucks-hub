import { expect, test } from './fixtures';

// Help guides (#5151): reachable from the sidebar footer, each with the short answer, the
// steps, an FAQ that matches its FAQPage data, and links to the download page and sign-up.
test('a guest finds a guide from the footer and reads it', async ({ page }) => {
    await page.goto('/');
    await page
        .locator('[data-test="legal-links"]')
        .getByRole('link', { name: 'Guides' })
        .click();
    await expect(page).toHaveURL(/\/guides$/);

    const list = page.locator('[data-test="guide-list"]');
    await expect(list.getByRole('link')).toHaveCount(6);
    await list
        .getByRole('link', { name: /How to start or join a virtual trucking/ })
        .click();
    await expect(page).toHaveURL(/\/guides\/start-or-join-a-vtc$/);

    await expect(
        page.getByRole('heading', {
            level: 1,
            name: /start or join a virtual/,
        }),
    ).toBeVisible();
    await expect(page.locator('[data-test="guide-answer"]')).toContainText(
        'New company',
    );
    await expect(page.locator('[data-test="guide-body"]')).toContainText(
        'Send application',
    );

    // The FAQ on the page is the one in the structured data. Blade prints that on a full
    // load (what a crawler gets), so read the server's HTML: an Inertia visit keeps the head.
    const faq = page.locator('[data-test="guide-faq"] h3');
    const html = await (await page.request.get(page.url())).text();
    const blocks = [
        ...html.matchAll(
            /<script type="application\/ld\+json">(.*?)<\/script>/gs,
        ),
    ];
    const data = JSON.parse(blocks[1][1]);
    expect(data['@type']).toBe('FAQPage');
    expect(await faq.allTextContents()).toEqual(
        data.mainEntity.map((q: { name: string }) => q.name),
    );

    const cta = page.locator('[data-test="guide-cta"]');
    await expect(
        cta.getByRole('link', { name: 'Create an account' }),
    ).toHaveAttribute('href', /\/register$/);
    await cta.getByRole('link', { name: 'Get ProjectTrucks' }).click();
    await expect(page).toHaveURL(/\/download$/);
});

test('links inside a guide lead to other guides and the Hub pages', async ({
    page,
}) => {
    await page.goto('/guides/ets2-leaderboards');
    await page
        .locator('[data-test="guide-body"]')
        .getByRole('link', { name: 'Leaderboards' })
        .click();
    await expect(page).toHaveURL(/\/leaderboards$/);

    await page.goto('/guides/ets2-leaderboards');
    await page
        .getByRole('navigation', { name: 'More guides' })
        .getByRole('link', { name: /telemetry plugin/ })
        .click();
    await expect(page).toHaveURL(/\/guides\/ets2-telemetry-plugin$/);
    await expect(page.locator('[data-test="guide-body"]')).toContainText(
        'scs-telemetry.dll',
    );
});

test('an unknown guide is a 404', async ({ page }) => {
    const response = await page.request.get('/guides/no-such-guide');
    expect(response.status()).toBe(404);
});
