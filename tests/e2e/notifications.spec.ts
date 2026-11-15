import { expect, logIn, test } from './fixtures';

// Notifications (#4643): the bell in the header and Settings → Notifications.
test('an application rings the owner’s bell and the decision rings the applicant’s', async ({
    browser,
}) => {
    const abeContext = await browser.newContext();
    const abe = await abeContext.newPage();
    await logIn(abe, 'abe-apply@example.com');

    // Nothing yet.
    const abeBell = abe.locator('[data-test="notification-bell"]');
    await expect(abeBell).toHaveAccessibleName('Notifications');
    await abeBell.click();
    await expect(abe.getByText('Nothing yet.')).toBeVisible();
    await abe.keyboard.press('Escape');

    await abe.goto('/companies/bell-freight');
    await abe.getByLabel('Message to the company').fill('Happy to drive nights.');
    await abe.locator('[data-test="apply"]').click();
    await expect(abe.getByText('Application sent', { exact: true })).toBeVisible();

    // Nora sees one unread notification and opens it: it goes to the company page.
    const noraContext = await browser.newContext();
    const nora = await noraContext.newPage();
    await logIn(nora, 'nora-notify@example.com');
    const noraBell = nora.locator('[data-test="notification-bell"]');
    await expect(noraBell).toHaveAccessibleName('Notifications, 1 unread');
    await expect(nora.locator('[data-test="notification-count"]')).toHaveText('1');
    await noraBell.click();
    const item = nora.locator('[data-test="notification-item"]', {
        hasText: 'Abe Apply applied to join Bell Freight.',
    });
    await expect(item).toHaveAttribute('data-read', 'false');
    await item.click();
    await expect(nora).toHaveURL(/\/companies\/bell-freight$/);
    await expect(noraBell).toHaveAccessibleName('Notifications');
    await expect(nora.locator('[data-test="notification-count"]')).toHaveCount(0);

    // Read now, still listed.
    await noraBell.click();
    await expect(
        nora.locator('[data-test="notification-item"]', {
            hasText: 'Abe Apply applied',
        }),
    ).toHaveAttribute('data-read', 'true');
    await nora.keyboard.press('Escape');

    const application = nora.locator('[data-test="application"]');
    await expect(application).toContainText('Happy to drive nights.');
    await application.getByRole('button', { name: 'Accept' }).click();
    await expect(application).toHaveCount(0);

    // Abe hears about it on his next page load and marks everything read.
    await abe.goto('/dashboard');
    await expect(abeBell).toHaveAccessibleName('Notifications, 1 unread');
    await abeBell.click();
    await expect(
        abe.locator('[data-test="notification-item"]', {
            hasText: 'Bell Freight accepted your application.',
        }),
    ).toBeVisible();
    await abe.getByRole('button', { name: 'Mark all read' }).click();
    await expect(abe.locator('[data-test="notification-count"]')).toHaveCount(0);
    // An open menu hides the rest of the page from assistive tech: close it first.
    await abe.keyboard.press('Escape');
    await expect(abeBell).toHaveAccessibleName('Notifications');

    await abeContext.close();
    await noraContext.close();
});

test('email settings: switching an email off sticks', async ({ page }) => {
    await logIn(page, 'abe-apply@example.com');
    await page.goto('/settings/notifications');

    const linked = page.getByRole('checkbox', {
        name: 'A ProjectTrucks app is linked to my account',
    });
    await expect(linked).toBeChecked();
    await linked.click();
    await expect(linked).not.toBeChecked();
    await page.getByRole('button', { name: 'Save' }).click();
    await expect(page.getByText('Email notifications saved.')).toBeVisible();

    await page.reload();
    await expect(
        page.getByRole('checkbox', {
            name: 'A ProjectTrucks app is linked to my account',
        }),
    ).not.toBeChecked();
    await expect(
        page.getByRole('checkbox', { name: 'Someone applies to my company' }),
    ).toBeChecked();
});
