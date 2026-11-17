import { expect, logIn, test } from './fixtures';

// Follows (#4647): Fay follows Fred from his profile; Home's Following tab shows his
// Bergen → Trondheim job and stays picked on her next visit.
test('a driver follows another and reads the Following feed', async ({ page }) => {
    await logIn(page, 'fay-follower@example.com');

    await page.goto('/');
    const tabs = page.locator('[data-test="home-feed-tabs"]');
    await expect(tabs.getByRole('tab')).toHaveText(['Everyone', 'Following']);
    await expect(tabs.getByRole('tab', { name: 'Everyone' })).toHaveAttribute(
        'aria-selected',
        'true',
    );
    await tabs.getByRole('tab', { name: 'Following' }).click();
    await expect(page).toHaveURL(/\?feed=following$/);
    await expect(
        page.getByText('No deliveries from drivers you follow yet.'),
    ).toBeVisible();

    await page.goto('/drivers/fred-followed');
    const counts = page.locator('[data-test="follow-counts"]');
    await expect(counts).toHaveText(/0 followers · 0 following/);
    const follow = page.locator('[data-test="follow"]');
    await expect(follow).toHaveText(/Follow/);
    await expect(follow).toHaveAttribute('aria-pressed', 'false');
    await follow.click();
    await expect(follow).toHaveAttribute('aria-pressed', 'true');
    await expect(follow).toHaveText(/Following/);
    await expect(counts).toHaveText(/1 follower · 0 following/);

    // Back home without ?feed=: the Following tab was remembered.
    await page.goto('/');
    await expect(tabs.getByRole('tab', { name: 'Following' })).toHaveAttribute(
        'aria-selected',
        'true',
    );
    await expect(
        page.locator('[data-test="job-row"]', { hasText: 'Trondheim' }),
    ).toBeVisible();

    // Unfollow from the profile; the feed empties again.
    await page.goto('/drivers/fred-followed');
    await follow.click();
    await expect(follow).toHaveAttribute('aria-pressed', 'false');
    await expect(counts).toHaveText(/0 followers · 0 following/);
    await page.goto('/');
    await expect(
        page.getByText('No deliveries from drivers you follow yet.'),
    ).toBeVisible();

    // Your own profile has counts but no button.
    await page.goto('/drivers/fay-follower');
    await expect(page.locator('[data-test="follow-counts"]')).toBeVisible();
    await expect(page.locator('[data-test="follow"]')).toHaveCount(0);
});
