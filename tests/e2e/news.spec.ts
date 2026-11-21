import { expect, logIn, test } from './fixtures';

// Company news (#5224): Nell posts, Ned reads (bell, company page, home tab), a guest
// only sees the public post. Ned switches the news bell off in Settings.
test('an owner posts news, members get it, guests only see public posts', async ({
    browser,
}) => {
    // Three browsers and a dozen page loads: more than the default 30 s on a busy machine.
    test.setTimeout(90_000);
    const nellContext = await browser.newContext();
    const nell = await nellContext.newPage();
    await logIn(nell, 'nell-news@example.com');

    await nell.goto('/companies/newsroom-haulage');
    const panel = nell.locator('[data-test="company-news"]');
    await expect(panel).toContainText('No news yet.');
    await panel.locator('[data-test="new-news"]').click();
    await expect(nell).toHaveURL(
        /\/companies\/newsroom-haulage\/news\/create$/,
    );

    // Members only is the default.
    await nell.getByLabel('Title').fill('Members briefing');
    await nell
        .getByLabel('Message')
        .fill('Fuel cards are in.\nRoute list: https://example.com/routes');
    await expect(nell.getByLabel('Members only')).toBeChecked();
    // No Discord webhook on this company, so no Discord box.
    await expect(nell.getByText('Also post to Discord')).toHaveCount(0);
    await nell.locator('[data-test="save-news"]').click();
    await expect(nell).toHaveURL(/\/companies\/newsroom-haulage$/);
    await expect(nell.getByText('News posted.')).toBeVisible();

    await nell.locator('[data-test="new-news"]').click();
    await nell.getByLabel('Title').fill('We are recruiting');
    await nell.getByLabel('Message').fill('Join us for Friday convoys.');
    await nell.getByLabel('Public').check();
    await nell.locator('[data-test="save-news"]').click();
    await expect(nell).toHaveURL(/\/companies\/newsroom-haulage$/);

    // Newest first, then Nell pins the briefing to the top.
    const posts = panel.locator('[data-test="news-post"]');
    await expect(posts).toHaveCount(2);
    await expect(posts.nth(0)).toContainText('We are recruiting');
    await posts
        .filter({ hasText: 'Members briefing' })
        .getByRole('button', { name: 'Pin to the top' })
        .click();
    await expect(posts.nth(0)).toContainText('Members briefing');
    await expect(
        posts.nth(0).getByRole('button', { name: 'Unpin' }),
    ).toBeVisible();

    // Body: line break kept, the link is a real link.
    const briefing = posts.nth(0);
    await expect(
        briefing.locator('[data-test="news-members-only"]'),
    ).toBeVisible();
    await expect(
        briefing.getByRole('link', { name: 'https://example.com/routes' }),
    ).toHaveAttribute('rel', /nofollow/);

    // Edit keeps it in place.
    await briefing.getByRole('link', { name: 'Edit' }).click();
    await expect(nell).toHaveURL(/\/news\/\d+\/edit$/);
    await nell.getByLabel('Title').fill('Members briefing (Friday)');
    await nell.locator('[data-test="save-news"]').click();
    await expect(posts.nth(0)).toContainText('Members briefing (Friday)');

    // Ned: the bell, the company page and the home page's My company tab.
    const nedContext = await browser.newContext();
    const ned = await nedContext.newPage();
    await logIn(ned, 'ned-reader@example.com');
    await ned.goto('/companies/newsroom-haulage');
    await expect(
        ned.getByRole('button', { name: /Notifications, 2 unread/ }),
    ).toBeVisible();
    const nedPanel = ned.locator('[data-test="company-news"]');
    await expect(nedPanel.locator('[data-test="news-post"]')).toHaveCount(2);
    await expect(nedPanel).toContainText('Members briefing (Friday)');
    // Drivers can't manage posts.
    await expect(nedPanel.locator('[data-test="new-news"]')).toHaveCount(0);
    await expect(nedPanel.getByRole('button', { name: 'Delete' })).toHaveCount(
        0,
    );

    await ned.goto('/?feed=company');
    const homeNews = ned.locator('[data-test="home-news"]');
    await expect(homeNews).toContainText('Members briefing (Friday)');
    await expect(homeNews).toContainText('[NWR] Newsroom Haulage');

    // Ned switches the news bell off.
    await ned.goto('/settings/notifications');
    const newsSwitch = ned.getByRole('checkbox', {
        name: 'News from my company',
    });
    await expect(newsSwitch).toBeChecked();
    await newsSwitch.click();
    await ned.getByRole('button', { name: 'Save' }).click();
    await expect(ned.getByText('Notification settings saved.')).toBeVisible();
    await ned.reload();
    await expect(
        ned.getByRole('checkbox', { name: 'News from my company' }),
    ).not.toBeChecked();

    // A guest sees the public post only.
    const guestContext = await browser.newContext();
    const guest = await guestContext.newPage();
    await guest.goto('/companies/newsroom-haulage');
    const guestPanel = guest.locator('[data-test="company-news"]');
    await expect(guestPanel.locator('[data-test="news-post"]')).toHaveCount(1);
    await expect(guestPanel).toContainText('We are recruiting');
    await expect(guest.getByText('Members briefing')).toHaveCount(0);

    // Nell deletes the public post; the guest's page then has no news panel.
    nell.once('dialog', (d) => d.accept());
    await posts
        .filter({ hasText: 'We are recruiting' })
        .getByRole('button', { name: 'Delete' })
        .click();
    await expect(nell.getByText('News deleted.')).toBeVisible();
    await expect(posts).toHaveCount(1);
    await guest.reload();
    await expect(guest.locator('[data-test="company-news"]')).toHaveCount(0);

    await nellContext.close();
    await nedContext.close();
    await guestContext.close();
});
