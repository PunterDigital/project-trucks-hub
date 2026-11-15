import { expect, job, logIn, test } from './fixtures';

/**
 * The whole ProjectTrucks round trip, with Playwright's `request` playing the
 * app: start a device link, approve the code in the browser, poll for the
 * token, push a job and a live status, and see them on the site.
 */
test('linking ProjectTrucks and syncing a job', async ({ page, request }) => {
    // The token poll alone waits out the 5 s interval.
    test.setTimeout(90_000);
    await logIn(page, 'erin@example.com');
    await expect(page.locator('[data-test="link-app-callout"]')).toBeVisible();

    const start = await request.post('/api/v1/device/code', {
        data: { client_name: 'ProjectTrucks on E2E-RIG' },
    });
    expect(start.ok()).toBeTruthy();
    const { device_code, user_code, verification_uri } = await start.json();

    const pending = await request.post('/api/v1/device/token', {
        data: { device_code },
    });
    expect(pending.status()).toBe(400);
    expect((await pending.json()).error).toBe('authorization_pending');

    // The driver types the code on /link, like they would from the app.
    await page.goto(verification_uri);
    await page.getByLabel('Code').fill(user_code.toLowerCase());
    await page.getByRole('button', { name: 'Continue' }).click();
    await expect(page.locator('[data-test="link-request"]')).toContainText(
        'ProjectTrucks on E2E-RIG',
    );
    await page.getByRole('button', { name: 'Approve' }).click();
    await expect(page).toHaveURL(/\/settings\/devices$/);

    // The app's next poll (after the 5 s interval) gets its token.
    let token = '';
    await expect(async () => {
        const res = await request.post('/api/v1/device/token', {
            data: { device_code },
        });
        expect(res.status()).toBe(200);
        token = (await res.json()).access_token;
    }).toPass({ intervals: [5_500], timeout: 30_000 });

    // The token now shows as a linked app.
    await page.reload();
    await expect(page.locator('[data-test="devices"]')).toContainText(
        'ProjectTrucks on E2E-RIG',
    );

    const auth = {
        Authorization: `Bearer ${token}`,
        Accept: 'application/json',
    };
    const synced = await request.post('/api/v1/jobs', {
        headers: auth,
        data: {
            jobs: [
                job({
                    source_city: 'Brussel',
                    dest_city: 'Lyon',
                    distance_km: 812,
                    // 812 km at 81.2 km/h: 10 in-game hours (#4771) and
                    // 10 real ones, so the Hub's checks (#4642) let it count.
                    duration_sec: 36_000,
                    game_duration_min: 600,
                    avg_speed_kmh: 81.2,
                    started_at: new Date(Date.now() - 36_060_000).toISOString(),
                }),
                // A job no truck could drive: the Hub flags it itself,
                // though the "app" sent flagged: false.
                job({
                    id: 'e2e-teleport',
                    source_city: 'Calais',
                    dest_city: 'Wien',
                    distance_km: 1200,
                    duration_sec: 7200,
                    // 1,200 km in two in-game hours.
                    game_duration_min: 120,
                    avg_speed_kmh: 600,
                    started_at: new Date(Date.now() - 10_800_000).toISOString(),
                    completed_at: new Date(
                        Date.now() - 3_600_000,
                    ).toISOString(),
                }),
                // Typed in by hand: older apps still send these, the Hub
                // drops them (#4751).
                job({
                    id: 'e2e-manual',
                    source: 'MANUAL',
                    source_city: 'Gent',
                    dest_city: 'Bordeaux',
                }),
            ],
        },
    });
    expect(synced.ok()).toBeTruthy();
    const syncedBody = await synced.json();
    expect(syncedBody.skipped).toEqual([
        { id: 'e2e-manual', reason: 'manual' },
    ]);
    const syncedJobs = syncedBody.jobs;
    expect(syncedJobs).toHaveLength(2);
    expect(syncedJobs[0].hub_flag_reason).toBeNull();
    expect(syncedJobs[1].hub_flag_reason).toContain(
        'Average speed over 150 km/h',
    );

    // The flagged job says why and stays off the stats.
    await page.goto(new URL(syncedJobs[1].url).pathname);
    await expect(page.locator('[data-test="hub-flag"]')).toContainText(
        "The Hub's checks flagged this job",
    );
    await expect(page.getByText('Flagged', { exact: true })).toBeVisible();

    const status = await request.put('/api/v1/status', {
        headers: auth,
        data: {
            truck: { make: 'DAF', model: 'XG+' },
            job: {
                cargo_name: 'Yoghurt',
                source_city: 'Lyon',
                destination_city: 'Milano',
                driven_km: 50,
                distance_left_km: 350,
                progress_pct: 12.5,
                parked: false,
            },
        },
    });
    expect(status.ok()).toBeTruthy();

    // Dashboard: the job and "right now".
    await page.goto('/dashboard');
    await expect(page.locator('[data-test="link-app-callout"]')).toHaveCount(0);
    await expect(
        page.locator('[data-test="job-row"]').filter({ hasText: 'Lyon' }),
    ).toBeVisible();
    await expect(
        page.locator('[data-test="job-row"]').filter({ hasText: 'Bordeaux' }),
    ).toHaveCount(0);
    await expect(page.locator('[data-test="on-the-road"]')).toContainText(
        'Milano',
    );

    // Public profile and the job page.
    await page.goto('/drivers/erin');
    await expect(
        page.locator('[data-test="stat"][data-label="deliveries"]'),
    ).toBeVisible();
    await page.getByRole('button', { name: 'All time' }).click();
    await expect(
        page.locator('[data-test="stat"][data-label="distance"]'),
    ).toContainText('812 km');
    await page
        .locator('[data-test="job-row"]')
        .getByRole('link')
        .first()
        .click();
    await expect(page.getByRole('heading', { level: 1 })).toContainText(
        'Brussel',
    );
    const jobUrl = page.url();

    // Erin tops this week's board (812 km beats Olga's 530).
    await page.goto('/leaderboards');
    await expect(
        page.locator('[data-test="leaderboard-row"]').first(),
    ).toContainText('Erin Example');

    // Hiding the job takes it away from everyone else.
    await page.goto(jobUrl);
    await page.locator('[data-test="toggle-hidden"]').click();
    await expect(page.locator('[data-test="toggle-hidden"]')).toContainText(
        'Show to others',
    );

    const guest = await page.context().browser()!.newContext();
    const guestPage = await guest.newPage();
    const response = await guestPage.goto(jobUrl);
    expect(response?.status()).toBe(404);
    await guest.close();

    // Unlinking from the website stops the token working.
    page.on('dialog', (d) => d.accept());
    await page.goto('/settings/devices');
    await page.getByRole('button', { name: 'Unlink' }).click();
    await expect(page.locator('[data-test="devices"]')).toHaveCount(0);
    const refused = await request.get('/api/v1/me', { headers: auth });
    expect(refused.status()).toBe(401);
});
