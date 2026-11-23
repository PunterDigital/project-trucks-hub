import { expect, logIn, test } from './fixtures';

// TruckersMP events (#5228): Tess fills a new event from a TruckersMP link. E2ESeeder put the
// recorded API answer for event 38846 in the cache, so nothing here calls TruckersMP; the
// route image is answered locally too.
const pixel = Buffer.from(
    'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mNkYAAAAAYAAjCB0C8AAAAASUVORK5CYII=',
    'base64',
);

test('an owner fills an event from TruckersMP, edits it and the page links back', async ({
    page,
}) => {
    await page.route('https://static.truckersmp.com/**', (route) =>
        route.fulfill({ status: 200, contentType: 'image/png', body: pixel }),
    );
    await logIn(page, 'tess-tmp@example.com');
    await page.goto('/companies/tmp-convoys/events/create');

    const link = page.getByLabel('TruckersMP event link');
    const fill = page.locator('[data-test="fill-truckersmp"]');

    // Not a TruckersMP link: a message, and the form stays as it was.
    await page.getByLabel('Title').fill('My own title');
    await link.fill('https://example.com/events/1');
    await fill.click();
    await expect(
        page.getByText('That isn’t a TruckersMP event link.'),
    ).toBeVisible();
    await expect(page.getByLabel('Title')).toHaveValue('My own title');

    await link.fill(
        'https://truckersmp.com/events/38846-community-contributor-convoy',
    );
    await fill.click();
    await expect(page.locator('[data-test="truckersmp-filled"]')).toBeVisible();
    await expect(page.getByLabel('Title')).toHaveValue(
        'Community Contributor Convoy',
    );
    await expect(page.getByLabel('Server')).toHaveValue('Event Server');
    await expect(page.getByLabel('Start city')).toHaveValue('Kalamata');
    await expect(page.getByLabel('Start company')).toHaveValue(
        'Assigned slots',
    );
    await expect(page.getByLabel('Destination city')).toHaveValue('Ioannina');
    await expect(page.getByLabel('Start time')).not.toHaveValue('');
    await expect(page.getByLabel('Route map image')).toHaveValue(
        /^https:\/\/static\.truckersmp\.com\/images\/event\/map\//,
    );
    await expect(link).toHaveValue('https://truckersmp.com/events/38846');

    // Still editable before saving.
    await page.getByLabel('Title').fill('TMP Greece convoy');
    await page.locator('[data-test="save-event"]').click();

    await expect(page).toHaveURL(/\/events\/\d+$/);
    await expect(
        page.getByRole('heading', { name: 'TMP Greece convoy' }),
    ).toBeVisible();
    await expect(page.locator('[data-test="truckersmp-link"]')).toHaveAttribute(
        'href',
        'https://truckersmp.com/events/38846',
    );
    await expect(page.locator('[data-test="route-image"] img')).toBeVisible();

    // The edit form keeps the link.
    await page.locator('[data-test="edit-event"]').click();
    await expect(page.getByLabel('TruckersMP event link')).toHaveValue(
        'https://truckersmp.com/events/38846',
    );
});
