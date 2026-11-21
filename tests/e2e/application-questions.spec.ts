import { expect, logIn, test } from './fixtures';

// Application questions (#5222): Quinn owns Question Time Haulage (applications). Quinn sets
// a required short question and an optional choice, Abby applies and sees her own answers,
// Quinn reads them next to the application, and a later edit doesn't change them.

test('a company asks its own questions and reads the answers', async ({
    page,
    browser,
}) => {
    await logIn(page, 'quinn-questions@example.com');
    await page.goto('/companies/question-time-haulage/edit');
    const section = page.locator('#questions');
    await expect(section).toContainText('No questions');

    await section.getByRole('button', { name: 'Add a question' }).click();
    await section
        .getByLabel('Question 1', { exact: true })
        .fill('Where do you usually drive?');
    await section
        .getByRole('checkbox', { name: 'Question 1 is required' })
        .click();

    await section.getByRole('button', { name: 'Add a question' }).click();
    await section
        .getByLabel('Question 2', { exact: true })
        .fill('Which server?');
    await section
        .locator('#question-type-1')
        .selectOption({ label: 'Pick one option' });
    await section
        .locator('#question-options-1')
        .fill('Simulation 1\nSimulation 2');

    await section.getByRole('button', { name: 'Save questions' }).click();
    await expect(page.getByText('Application questions saved.')).toBeVisible();
    // After the save the page comes back with the stored questions.
    await expect(section.locator('[data-test="question"]')).toHaveCount(2);
    await expect(section.getByLabel('Question 2', { exact: true })).toHaveValue(
        'Which server?',
    );

    // Abby applies: the required question must be answered.
    const abbyContext = await browser.newContext();
    const abby = await abbyContext.newPage();
    await logIn(abby, 'abby-answers@example.com');
    await abby.goto('/companies/question-time-haulage');
    await expect(abby.locator('[data-test="apply-question"]')).toHaveCount(2);
    const where = abby.getByRole('textbox', {
        name: 'Where do you usually drive?',
    });
    await expect(where).toHaveAttribute('required', '');
    await where.fill('Scandinavia and the Baltics');
    await abby
        .getByRole('combobox', { name: 'Which server?' })
        .selectOption('Simulation 2');
    await abby.getByLabel('Message to the company').fill('Hi Quinn!');
    await abby.locator('[data-test="apply"]').click();
    await expect(abby.getByText('Application sent.')).toBeVisible();

    const own = abby.locator('[data-test="answers"]');
    await expect(own).toContainText('Where do you usually drive?');
    await expect(own).toContainText('Scandinavia and the Baltics');
    await expect(own).toContainText('Simulation 2');
    await abbyContext.close();

    // Quinn sees the answers next to the application.
    await page.goto('/companies/question-time-haulage');
    const application = page.locator('[data-test="application"]', {
        hasText: 'Abby Answers',
    });
    await expect(application).toContainText('Hi Quinn!');
    await expect(application.locator('[data-test="answers"]')).toContainText(
        'Scandinavia and the Baltics',
    );
    await expect(application.locator('[data-test="answers"]')).toContainText(
        'Which server?',
    );

    // Renaming a question later leaves the answer as it was asked.
    await page.goto('/companies/question-time-haulage/edit');
    await section
        .getByLabel('Question 1', { exact: true })
        .fill('What is your favourite route?');
    await section.getByRole('button', { name: 'Save questions' }).click();
    await expect(page.getByText('Application questions saved.')).toBeVisible();
    await page.goto('/companies/question-time-haulage');
    await expect(application.locator('[data-test="answers"]')).toContainText(
        'Where do you usually drive?',
    );
    await expect(application).not.toContainText('favourite route');
});
