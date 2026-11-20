import { expect, test } from './fixtures';
import { logInWithTotp } from './totp';

// The blog (#5150). Seeded: the published "welcome" post; bea-blogger (staff, 2FA) writes,
// previews and publishes another one.
const BEA_SECRET = 'GEZDGNBVGY3TQOJQGEZDGNBVGY3TQOJQ';

test('guests read the blog and its RSS feed', async ({ page }) => {
    await page.goto('/');
    await page.getByRole('link', { name: 'Blog', exact: true }).click();
    await expect(page).toHaveURL(/\/blog$/);
    await expect(
        page.getByRole('heading', { name: 'Blog', level: 1 }),
    ).toBeVisible();

    await page
        .locator('[data-test="blog-posts"]')
        .getByRole('link', { name: /Welcome to the ProjectTrucks blog/ })
        .click();
    await expect(page).toHaveURL(/\/blog\/welcome$/);
    await expect(page).toHaveTitle(/^Welcome to the ProjectTrucks blog - /);
    const body = page.locator('[data-test="blog-body"]');
    await expect(body.locator('strong')).toHaveText('release notes');
    await expect(
        body.getByRole('heading', { name: "What's next" }),
    ).toBeVisible();

    const feed = await page.request.get('/blog/feed');
    expect(feed.status()).toBe(200);
    expect(feed.headers()['content-type']).toContain('application/rss+xml');
    expect(await feed.text()).toContain(
        '<title>Welcome to the ProjectTrucks blog</title>',
    );
});

test('staff write a draft, preview it and publish it', async ({
    page,
    browser,
}) => {
    test.setTimeout(90_000);

    await logInWithTotp(page, 'bea-blogger@example.com', BEA_SECRET);
    await page.goto('/admin/posts');
    await page.getByRole('link', { name: 'New post' }).click();
    await expect(page).toHaveURL(/\/admin\/posts\/create$/);

    await page
        .getByRole('textbox', { name: /^Title/ })
        .fill('Notes from the road');
    const address = page.getByRole('textbox', { name: /^Address/ });
    await address.click();
    // The title fills an empty address once it loses focus.
    await expect(address).toHaveValue('notes-from-the-road');
    await page
        .getByRole('textbox', { name: /^Summary/ })
        .fill('What we learned driving 10,000 km this month.');
    await page.locator('.CodeMirror').click();
    await page.keyboard.type('Hello **drivers**.');
    await page.keyboard.press('Enter');
    await page.keyboard.press('Enter');
    await page.keyboard.type('<script>window.pwned = 1</script>');
    await expect(page.getByRole('combobox', { name: /^Status/ })).toHaveValue(
        'draft',
    );
    await page.getByRole('button', { name: 'Create', exact: true }).click();
    await expect(page).toHaveURL(/\/admin\/posts\/\d+\/edit$/);

    // A guest can't see the draft. A separate browser, so the expected 404 isn't a console
    // error in the test page.
    const guestContext = await browser.newContext();
    const guest = await guestContext.newPage();
    expect((await guest.goto('/blog/notes-from-the-road'))?.status()).toBe(404);

    // The preview is the real post page, marked as a draft. The body's HTML was removed.
    const previewUrl = await page
        .getByRole('link', { name: 'Preview' })
        .getAttribute('href');
    expect(previewUrl).toMatch(/\/blog\/preview\/\d+$/);
    const preview = await page.context().newPage();
    await preview.goto(previewUrl!);
    await expect(
        preview.locator('[data-test="blog-preview-note"]'),
    ).toContainText('Draft preview');
    await expect(
        preview.getByRole('heading', { name: 'Notes from the road' }),
    ).toBeVisible();
    const previewBody = preview.locator('[data-test="blog-body"]');
    await expect(previewBody.locator('strong')).toHaveText('drivers');
    await expect(previewBody.locator('script')).toHaveCount(0);
    expect(await preview.evaluate(() => 'pwned' in window)).toBe(false);
    await preview.close();

    // Publish.
    await page
        .getByRole('combobox', { name: /^Status/ })
        .selectOption('published');
    await page.getByRole('button', { name: 'Save changes' }).click();
    await expect(
        page.getByRole('link', { name: 'View on the blog' }),
    ).toBeVisible();

    // Now everyone reads it, and it tops the list.
    expect((await guest.goto('/blog/notes-from-the-road'))?.status()).toBe(200);
    await expect(
        guest.getByRole('heading', { name: 'Notes from the road' }),
    ).toBeVisible();
    await expect(guest.locator('[data-test="blog-preview-note"]')).toHaveCount(
        0,
    );
    await guest.goto('/blog');
    await expect(
        guest.locator('[data-test="blog-posts"] h2').first(),
    ).toHaveText('Notes from the road');
    await guestContext.close();
});
