import { expect, logIn, test } from './fixtures';

// Kudos and comments on jobs (#4646): Kira reacts to Jack's Rotterdam → Lyon job.
test('a driver gives kudos and comments; the job’s driver hears about it and deletes the comment', async ({
    browser,
}) => {
    const kiraContext = await browser.newContext();
    const kira = await kiraContext.newPage();
    await logIn(kira, 'kira-kudos@example.com');

    // Kudos from the profile's job list.
    await kira.goto('/drivers/jack-job');
    const row = kira.locator('[data-test="job-row"]', { hasText: 'Lyon' });
    const kudos = row.locator('[data-test="kudos"]');
    await expect(kudos).toHaveAccessibleName('Give kudos (0 kudos)');
    await expect(kudos).toHaveAttribute('aria-pressed', 'false');
    await kudos.click();
    await expect(kudos).toHaveAttribute('aria-pressed', 'true');
    await expect(kudos).toHaveAccessibleName('Take back kudos (1 kudos)');

    // A comment on the job page; links stay plain text.
    await row.getByRole('link', { name: /Rotterdam/ }).click();
    await expect(kira).toHaveURL(/\/jobs\/\d+$/);
    await expect(kira.locator('[data-test="kudos"]')).toHaveAttribute(
        'aria-pressed',
        'true',
    );
    await expect(kira.getByText('No comments yet.')).toBeVisible();
    const post = kira.locator('[data-test="post-comment"]');
    await expect(post).toBeDisabled();
    await kira
        .getByLabel('Write a comment')
        .fill('Lovely tulip run! https://example.com');
    await post.click();
    const comment = kira.locator('[data-test="comment"]', {
        hasText: 'Lovely tulip run!',
    });
    await expect(comment).toBeVisible();
    await expect(comment).toContainText('Kira Kudos');
    await expect(comment.locator('[data-test="comment-body"] a')).toHaveCount(
        0,
    );
    await expect(kira.getByLabel('Write a comment')).toHaveValue('');
    await expect(
        kira.getByRole('heading', { name: 'Comments (1)' }),
    ).toBeVisible();
    const jobUrl = kira.url();

    // Jack: two bell items, the count (no button on his own job), and he removes the comment.
    const jackContext = await browser.newContext();
    const jack = await jackContext.newPage();
    await logIn(jack, 'jack-job@example.com');
    const bell = jack.locator('[data-test="notification-bell"]');
    await expect(bell).toHaveAccessibleName('Notifications, 2 unread');
    await bell.click();
    await expect(
        jack.locator('[data-test="notification-item"]', {
            hasText: 'Kira Kudos gave kudos to your job Rotterdam → Lyon.',
        }),
    ).toBeVisible();
    await jack
        .locator('[data-test="notification-item"]', {
            hasText: 'Kira Kudos commented on your job Rotterdam → Lyon',
        })
        .click();
    await expect(jack).toHaveURL(/\/jobs\/\d+#comments$/);

    await expect(jack.locator('[data-test="kudos"]')).toHaveCount(0);
    await expect(
        jack.locator('[data-test="kudos-count"]'),
    ).toHaveAccessibleName('1 kudos');
    await jack
        .locator('[data-test="comment"]', { hasText: 'Lovely tulip run!' })
        .getByRole('button', { name: 'Delete comment' })
        .click();
    await expect(jack.getByText('No comments yet.')).toBeVisible();

    // Kira takes her kudos back from the job page.
    await kira.goto(jobUrl);
    await expect(kira.getByText('No comments yet.')).toBeVisible();
    await kira.locator('[data-test="kudos"]').click();
    await expect(kira.locator('[data-test="kudos"]')).toHaveAccessibleName(
        'Give kudos (0 kudos)',
    );

    // A guest sees no buttons, just a way to log in.
    const guestContext = await browser.newContext();
    const guest = await guestContext.newPage();
    await guest.goto(jobUrl);
    await expect(guest.getByText('to give kudos or comment.')).toBeVisible();
    await expect(guest.locator('[data-test="kudos"]')).toHaveCount(0);
    await expect(guest.getByLabel('Write a comment')).toHaveCount(0);

    await kiraContext.close();
    await jackContext.close();
    await guestContext.close();
});
