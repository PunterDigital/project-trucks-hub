import { expect, logIn, test } from './fixtures';

// Company events and convoys (#4648): Eve plans one, Max answers, Otto can only look.
test('an owner plans a convoy, a member says going, an outsider is asked to join, then it is cancelled', async ({
    browser,
}) => {
    const eveContext = await browser.newContext();
    const eve = await eveContext.newPage();
    await logIn(eve, 'eve-events@example.com');

    await eve.goto('/companies/convoy-club');
    const panel = eve.locator('[data-test="company-events"]');
    await expect(panel).toContainText('No upcoming events.');
    await panel.locator('[data-test="new-event"]').click();
    await expect(eve).toHaveURL(/\/companies\/convoy-club\/events\/create$/);

    await eve.getByLabel('Title').fill('Sunday Rotterdam run');
    await eve.getByLabel('Start time').fill('2030-01-06T19:30');
    await eve.getByLabel('Server').fill('Simulation 1');
    await eve.getByLabel('Start city').fill('Rotterdam');
    await eve.getByLabel('Destination city').fill('Hamburg');
    await eve.getByLabel('Route notes').fill('Meet at the port.');
    await eve.getByLabel('Max drivers').fill('2');
    await eve.locator('[data-test="save-event"]').click();

    await expect(eve).toHaveURL(/\/events\/\d+$/);
    await expect(
        eve.getByRole('heading', { name: 'Sunday Rotterdam run' }),
    ).toBeVisible();
    // Typed in local time, shown back in local time.
    await expect(eve.locator('[data-test="event-time"]')).toContainText(
        'Sun 6 Jan',
    );
    await expect(eve.locator('[data-test="event-time"]')).toContainText(
        '19:30',
    );
    await expect(eve.getByText('Meet at the port.')).toBeVisible();
    const eventUrl = eve.url();

    // The .ics download.
    const href = await eve.locator('[data-test="ics"]').getAttribute('href');
    const ics = await eve.request.get(href!);
    expect(ics.status()).toBe(200);
    expect(ics.headers()['content-type']).toContain('text/calendar');
    expect(await ics.text()).toContain('SUMMARY:[CVY] Sunday Rotterdam run');

    // Max finds it on /events and says he's going.
    const maxContext = await browser.newContext();
    const max = await maxContext.newPage();
    await logIn(max, 'max-member@example.com');
    await max.goto('/events');
    const row = max.locator('[data-test="event-row"]', {
        hasText: 'Sunday Rotterdam run',
    });
    await expect(row).toContainText('[CVY] Convoy Club');
    await expect(row).toContainText('0/2 going');
    await row.getByRole('link', { name: 'Sunday Rotterdam run' }).click();
    await max.locator('[data-test="rsvp-going"]').click();
    await expect(max.locator('[data-test="rsvp-going"]')).toHaveAttribute(
        'aria-pressed',
        'true',
    );
    await expect(max.locator('[data-test="going"]')).toContainText(
        'Max Member',
    );
    await expect(
        max.getByRole('heading', { name: 'Going (1 of 2)' }),
    ).toBeVisible();

    // Otto isn't in the company and the event isn't open.
    const ottoContext = await browser.newContext();
    const otto = await ottoContext.newPage();
    await logIn(otto, 'otto-outsider@example.com');
    await otto.goto(eventUrl);
    await expect(otto.locator('[data-test="join-to-attend"]')).toContainText(
        'Join Convoy Club to attend.',
    );
    await expect(otto.locator('[data-test="rsvp-going"]')).toHaveCount(0);
    await expect(otto.locator('[data-test="going"]')).toContainText(
        'Max Member',
    );

    // Eve cancels; Max hears about it.
    await eve.goto(eventUrl);
    await eve.locator('[data-test="cancel-event"]').click();
    await expect(eve.locator('[data-test="event-cancelled"]')).toBeVisible();
    await expect(eve.locator('[data-test="edit-event"]')).toHaveCount(0);

    await max.goto('/dashboard');
    const bell = max.locator('[data-test="notification-bell"]');
    await expect(bell).toHaveAccessibleName('Notifications, 1 unread');
    await bell.click();
    await expect(
        max.locator('[data-test="notification-item"]', {
            hasText: 'Convoy Club cancelled “Sunday Rotterdam run”',
        }),
    ).toBeVisible();
    await max.keyboard.press('Escape');
    await max.goto('/events');
    await expect(
        max.locator('[data-test="event-row"]', {
            hasText: 'Sunday Rotterdam run',
        }),
    ).toHaveCount(0);

    await eveContext.close();
    await maxContext.close();
    await ottoContext.close();
});
