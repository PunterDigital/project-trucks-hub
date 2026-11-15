import { expect, logIn, test } from './fixtures';

// Company Discord webhook (#4652). The test message isn't clicked: it would call Discord.
test('an owner connects a Discord webhook, sees only its last characters, and removes it', async ({
    page,
}) => {
    await logIn(page, 'dina-discord@example.com');
    await page.goto('/companies/dispatch-club/edit');

    const section = page.locator('#discord');
    const field = section.getByLabel('Webhook URL');
    await expect(field).toHaveAttribute('type', 'password');
    await expect(
        section.getByRole('button', { name: 'Send test message' }),
    ).toHaveCount(0);

    // Anything but a Discord webhook URL is refused.
    await field.fill('https://example.com/api/webhooks/1/abc');
    await section.getByRole('button', { name: 'Save webhook' }).click();
    await expect(
        section.getByText('Paste a Discord webhook URL'),
    ).toBeVisible();

    const url =
        'https://discord.com/api/webhooks/123456789012345678/e2e-token-abcdefghijklmnop-wxyz';
    await field.fill(url);
    await section.getByRole('button', { name: 'Save webhook' }).click();
    await expect(
        page.getByText(
            'Discord webhook saved. Send a test message to check it.',
        ),
    ).toBeVisible();
    await expect(section.locator('[data-test="discord-status"]')).toHaveText(
        /Connected: the webhook ending in …wxyz\./,
    );
    // The field is cleared and the page never holds the URL again.
    await expect(section.getByLabel('Replace the webhook URL')).toHaveValue('');
    await page.reload();
    expect(await page.content()).not.toContain('e2e-token-abcdefghijklmnop');
    await expect(
        section.getByRole('button', { name: 'Send test message' }),
    ).toBeVisible();

    await section.getByRole('button', { name: 'Remove webhook' }).click();
    await expect(page.getByText('Discord webhook removed.')).toBeVisible();
    await expect(section.locator('[data-test="discord-status"]')).toHaveCount(
        0,
    );
    await expect(section.getByLabel('Webhook URL')).toBeVisible();
});
