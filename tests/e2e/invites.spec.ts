import type { Page } from '@playwright/test';
import { expect, logIn, test } from './fixtures';

// Company invite links (#5221): Ivy owns Invite Only Freight, whose recruitment is closed.
// Ian joins with a single-use link; Gwen opens a reusable one signed out, signs in from the
// invite page and comes back to it; then Ivy revokes it.

async function createLink(page: Page, expires: string, uses: string) {
    await page.goto('/companies/invite-only-freight/edit');
    const section = page.locator('#invites');
    await section.getByLabel('Expires').selectOption({ label: expires });
    await section.getByLabel('Can be used').selectOption({ label: uses });
    await section.locator('[data-test="create-invite"]').click();
    const url = section.locator('[data-test="new-invite-url"]');
    await expect(url).toHaveValue(/\/invite\/[A-Za-z0-9]{40}$/);

    return url.inputValue();
}

test('a single-use link lets one driver into a closed company', async ({
    page,
    browser,
}) => {
    await logIn(page, 'ivy-invite@example.com');
    const link = await createLink(page, 'After 7 days', 'Once');

    const invites = page.locator('#invites [data-test="invite"]');
    await expect(invites).toHaveCount(1);
    await expect(invites.locator('[data-test="invite-uses"]')).toHaveText(
        '0 of 1 used',
    );

    // Ian: the company page offers no way in (closed), the link does.
    const ianContext = await browser.newContext();
    const ian = await ianContext.newPage();
    await logIn(ian, 'ian-invitee@example.com');
    await ian.goto(link);
    await expect(ian.locator('[data-test="invite-page"]')).toContainText(
        'Invite Only Freight',
    );
    await ian.locator('[data-test="accept-invite"]').click();
    await expect(ian).toHaveURL(/\/companies\/invite-only-freight$/);
    await expect(
        ian.getByText('Welcome to Invite Only Freight!'),
    ).toBeVisible();
    await expect(
        ian.getByRole('button', { name: 'Leave company' }),
    ).toBeVisible();
    await ianContext.close();

    // Used up: gone from the list, and the link now says so.
    await page.reload();
    await expect(page.locator('#invites')).toContainText(
        'No live invite links.',
    );
    const guestContext = await browser.newContext();
    const guest = await guestContext.newPage();
    await guest.goto(link);
    await expect(guest.locator('[data-test="invite-problem"]')).toHaveText(
        'This invite link has been used up.',
    );
    await expect(guest.locator('[data-test="accept-invite"]')).toHaveCount(0);
    await guestContext.close();
});

test('a guest signs in from an invite link, joins, and the link is revoked', async ({
    page,
    browser,
}) => {
    await logIn(page, 'ivy-invite@example.com');
    const link = await createLink(page, 'Never', 'Any number of times');

    const gwenContext = await browser.newContext();
    const gwen = await gwenContext.newPage();
    await gwen.goto(link);
    const invite = gwen.locator('[data-test="invite-page"]');
    await expect(invite).toContainText(
        "You've been invited to join Invite Only Freight.",
    );
    await invite.getByRole('link', { name: 'Log in' }).click();
    await expect(gwen).toHaveURL(/\/login$/);
    await gwen.getByLabel('Email address').fill('gwen-guest@example.com');
    await gwen.getByLabel('Password', { exact: true }).fill('password');
    await gwen.getByRole('button', { name: 'Log in' }).click();

    // Back on the invite page, signed in.
    await expect(gwen).toHaveURL(link);
    await gwen.locator('[data-test="accept-invite"]').click();
    await expect(gwen).toHaveURL(/\/companies\/invite-only-freight$/);
    await gwenContext.close();

    // Ivy sees one use, then revokes the link.
    await page.reload();
    const row = page.locator('#invites [data-test="invite"]');
    await expect(row.locator('[data-test="invite-uses"]')).toHaveText('1 used');
    page.once('dialog', (dialog) => dialog.accept());
    await row.getByRole('button', { name: 'Revoke' }).click();
    await expect(page.getByText('Invite link revoked.')).toBeVisible();
    await expect(page.locator('#invites')).toContainText(
        'No live invite links.',
    );

    const guestContext = await browser.newContext();
    const guest = await guestContext.newPage();
    await guest.goto(link);
    await expect(guest.locator('[data-test="invite-problem"]')).toHaveText(
        'This invite link has been revoked.',
    );
    await guestContext.close();
});
