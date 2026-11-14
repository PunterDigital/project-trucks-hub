import { expect, logIn, test } from './fixtures';

test('applying to a company and being accepted', async ({ browser }) => {
    // Erin applies to Olga's company.
    const erinContext = await browser.newContext();
    const erin = await erinContext.newPage();
    await logIn(erin, 'erin@example.com');
    await erin.goto('/companies/nordic-haulage');
    await erin
        .getByLabel('Message to the company')
        .fill('Hi! I mostly drive Scandinavia.');
    await erin.locator('[data-test="apply"]').click();
    await expect(
        erin.getByText('Application sent', { exact: true }),
    ).toBeVisible();

    // Olga accepts.
    const olgaContext = await browser.newContext();
    const olga = await olgaContext.newPage();
    await logIn(olga, 'olga@example.com');
    await olga.goto('/companies/nordic-haulage');
    const application = olga.locator('[data-test="application"]');
    await expect(application).toContainText('I mostly drive Scandinavia.');
    await application.getByRole('button', { name: 'Accept' }).click();
    await expect(application).toHaveCount(0);
    await expect(olga.locator('[data-test="member"]')).toHaveCount(2);

    // Olga promotes Erin to manager.
    const erinRow = olga.locator('[data-test="member"]', { hasText: 'Erin' });
    await erinRow.getByLabel('Role of Erin Example').selectOption('manager');
    await expect(erinRow).toContainText('manager');
    await olga.reload();
    await expect(
        olga.locator('[data-test="member"]', { hasText: 'Erin' }),
    ).toContainText('manager');

    // Erin now sees the company on her dashboard and can leave it.
    await erin.goto('/dashboard');
    await expect(erin.getByText('[NRDC] Nordic Haulage')).toBeVisible();
    erin.on('dialog', (d) => d.accept());
    await erin.goto('/companies/nordic-haulage');
    await erin.getByRole('button', { name: 'Leave company' }).click();
    await expect(erin.locator('[data-test="apply"]')).toBeVisible();

    await erinContext.close();
    await olgaContext.close();
});

test('creating a company from scratch', async ({ page }) => {
    await logIn(page, 'erin@example.com');
    await page.goto('/companies/create');
    await page.getByLabel('Company name').fill('Erin Express');
    await page.getByLabel('Tag').fill('erx');
    await page.getByLabel('About').fill('Fast and friendly.');
    await page.getByText('Open', { exact: true }).click();
    await page.locator('[data-test="create-company"]').click();

    await expect(page).toHaveURL(/\/companies\/erin-express$/);
    await expect(page.getByRole('heading', { level: 1 })).toContainText(
        '[ERX]',
    );
    await expect(page.locator('[data-test="member"]')).toContainText('owner');
});
