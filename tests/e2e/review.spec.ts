import { expect, logIn, test } from './fixtures';
import { logInWithTotp } from './totp';

// #4743: a flagged job waits for a staff review. Only its driver sees it until staff approve it.
// Seeded: rhea-review (one Hub-flagged job, Groningen → Bremen), ray-reviewer (staff, 2FA).
const RAY_SECRET = 'KRSXG5CTMVRXEZLUKRSXG5CTMVRXEZLU';

test('a flagged job is pending review until staff approve it', async ({
    page,
    browser,
}) => {
    test.setTimeout(90_000);

    // The driver sees the job, marked pending, with why and how long it takes.
    await logIn(page, 'rhea-review@example.com');
    await page.goto('/drivers/rhea-review');
    const listed = page
        .locator('a[href*="/jobs/"]')
        .filter({ hasText: 'Groningen' })
        .first();
    await expect(listed).toBeVisible();
    await expect(page.locator('[data-test="review-pending"]')).toBeVisible();
    await listed.click();
    await expect(page).toHaveURL(/\/jobs\/\d+$/);
    const jobPath = new URL(page.url()).pathname;
    const note = page.locator('[data-test="review-pending-note"]');
    await expect(note).toContainText('Pending review');
    await expect(note).toContainText('Average speed over 150 km/h');
    await expect(note).toContainText(
        'Reviews usually take no more than 12 hours.',
    );

    // Everyone else: no job page, and nothing on Rhea's profile. A separate browser, so the
    // expected 404 isn't a console error in the test page.
    const guestContext = await browser.newContext();
    const guest = await guestContext.newPage();
    expect((await guest.goto(jobPath))?.status()).toBe(404);
    await guest.goto('/drivers/rhea-review');
    await expect(
        guest.getByRole('heading', { name: 'Rhea Review' }),
    ).toBeVisible();
    await expect(
        guest.locator('a[href*="/jobs/"]').filter({ hasText: 'Groningen' }),
    ).toHaveCount(0);

    // Staff find it under Pending review and approve it.
    const staffContext = await browser.newContext();
    const staff = await staffContext.newPage();
    await logInWithTotp(staff, 'ray-reviewer@example.com', RAY_SECRET);
    await staff.goto('/admin/jobs');
    await staff.getByRole('button', { name: /^Pending review/ }).click();
    // The tab has switched once other drivers' clean jobs are gone.
    await expect(
        staff.locator('.fi-ta-row').filter({ hasText: 'Olga Owner' }),
    ).toHaveCount(0);
    const row = staff.locator('.fi-ta-row').filter({ hasText: 'Rhea Review' });
    await expect(row).toHaveCount(1);
    await expect(row).toContainText('Pending review');
    await row.getByRole('link').first().click();
    await expect(staff).toHaveURL(/\/admin\/jobs\/\d+$/);

    await expect(async () => {
        await staff
            .getByRole('button', { name: 'Approve', exact: true })
            .click();
        await expect(
            staff.getByRole('heading', { name: 'Approve this job?' }),
        ).toBeVisible({ timeout: 2000 });
    }).toPass();
    await staff
        .getByRole('dialog')
        .getByRole('textbox', { name: /^Note/ })
        .fill('Checked the route, fine');
    await staff
        .getByRole('dialog')
        .getByRole('button', { name: 'Approve' })
        .click();
    await expect(staff.getByText('Job approved')).toBeVisible();
    await expect(
        staff.getByRole('button', { name: 'Reject', exact: true }),
    ).toBeVisible();

    // Now it is public, with no review badge for anyone.
    expect((await guest.goto(jobPath))?.status()).toBe(200);
    await expect(
        guest.getByRole('heading', { name: /Groningen/ }),
    ).toBeVisible();
    await guest.goto('/drivers/rhea-review');
    await expect(
        guest.locator('a[href*="/jobs/"]').filter({ hasText: 'Groningen' }),
    ).toHaveCount(1);
    await page.reload();
    await expect(page.locator('[data-test="review-pending-note"]')).toHaveCount(
        0,
    );

    await staffContext.close();
    await guestContext.close();
});
