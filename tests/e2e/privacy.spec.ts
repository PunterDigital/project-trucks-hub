import { readFile } from 'node:fs/promises';
import { expect, logIn, test } from './fixtures';

// #4641: privacy policy, terms, data export and "delete my synced jobs".
test('a guest finds the privacy policy and terms from the sidebar and sign-up', async ({
    page,
}) => {
    await page.goto('/');
    const legal = page.locator('[data-test="legal-links"]');
    await legal.getByRole('link', { name: 'Privacy' }).click();
    await expect(page).toHaveURL(/\/privacy$/);
    await expect(page.locator('[data-test="privacy-policy"]')).toContainText(
        'What never leaves your PC',
    );

    await page
        .locator('[data-test="legal-links"]')
        .getByRole('link', { name: 'Terms' })
        .click();
    await expect(page).toHaveURL(/\/terms$/);
    await expect(page.locator('[data-test="terms"]')).toContainText(
        'Fair play',
    );

    await page.goto('/register');
    await expect(page.locator('[data-test="register-legal"]')).toContainText(
        'privacy policy',
    );
});

test('a driver downloads their data and deletes their synced jobs', async ({
    page,
}) => {
    await logIn(page, 'paula@example.com');

    await page.goto('/settings/privacy');
    await expect(page.locator('[data-test="job-count"]')).toContainText(
        '1 synced job',
    );

    const [download] = await Promise.all([
        page.waitForEvent('download'),
        page.locator('[data-test="export-data"]').click(),
    ]);
    expect(download.suggestedFilename()).toMatch(
        /^projecttrucks-hub-paula-privacy-\d{4}-\d{2}-\d{2}\.json$/,
    );
    const file = await download.path();
    const data = JSON.parse(await readFile(file, 'utf8'));
    expect(data.account.email).toBe('paula@example.com');
    expect(data.jobs).toHaveLength(1);
    expect(data.jobs[0].cargo_name).toBe('Cheese');

    await page.locator('[data-test="delete-jobs-button"]').click();
    await expect(page.getByRole('dialog')).toContainText(
        'Delete all synced jobs?',
    );
    await page.locator('[data-test="confirm-delete-jobs"]').click();
    await expect(page.locator('[data-test="job-count"]')).toContainText(
        '0 synced jobs',
    );
    await expect(
        page.locator('[data-test="delete-jobs-button"]'),
    ).toBeDisabled();

    // The account is still there.
    await page.goto('/drivers/paula-privacy');
    await expect(page.getByText('Paula Privacy').first()).toBeVisible();
});
