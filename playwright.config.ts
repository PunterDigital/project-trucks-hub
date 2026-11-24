import { tmpdir } from 'node:os';
import { join } from 'node:path';
import { defineConfig, devices } from '@playwright/test';

const port = Number(process.env.E2E_PORT ?? 8775);
const baseURL = `http://127.0.0.1:${port}`;

// Env beats `.env` in Laravel: a throwaway SQLite file, nothing that reaches a real
// service. tests/e2e/serve.mjs resets the file and seeds it (E2ESeeder).
const appEnv = {
    E2E_PORT: String(port),
    APP_ENV: 'e2e',
    APP_DEBUG: 'true',
    APP_URL: baseURL,
    DB_CONNECTION: 'sqlite',
    DB_DATABASE:
        process.env.E2E_DB ?? join(tmpdir(), 'pt-hub-e2e', 'e2e.sqlite'),
    // Uploads (#4644) go to a throwaway folder, never the checkout's storage/.
    PUBLIC_DISK_ROOT: join(tmpdir(), 'pt-hub-e2e', 'public'),
    CACHE_STORE: 'database',
    QUEUE_CONNECTION: 'sync',
    SESSION_DRIVER: 'file',
    MAIL_MAILER: 'array',
    LOG_CHANNEL: 'single',
    // Steam stays off: its OpenID page and button image are real outside services.
    STEAM_LOGIN: 'false',
    STEAM_API_KEY: '',
    // Sentry (#4653) stays off whatever the local .env says.
    SENTRY_LARAVEL_DSN: '',
    INERTIA_SSR_ENABLED: 'false',
    // No board cache (#5734): specs sync a job and look for it on the board at once.
    HUB_BOARD_CACHE_SECONDS: '0',
    // Repo post drafts (#5153) come from a fixture folder, never resources/posts.
    BLOG_DRAFTS_PATH: join(process.cwd(), 'tests', 'fixtures', 'posts'),
};

// A second server with Inertia SSR on (#5157), talking to the built bootstrap/ssr/app.js,
// for ssr.spec.ts only. Needs `npm run build:ssr` first. The bundle listens on the port
// baked in at build time (INERTIA_SSR_PORT, default 13722).
const ssrPort = port + 2;
const ssrURL = `http://127.0.0.1:${ssrPort}`;
const ssrNodePort = Number(process.env.INERTIA_SSR_PORT ?? 13722);
const ssrEnv = {
    ...appEnv,
    E2E_PORT: String(ssrPort),
    APP_URL: ssrURL,
    DB_DATABASE: join(tmpdir(), 'pt-hub-e2e', 'e2e-ssr.sqlite'),
    PUBLIC_DISK_ROOT: join(tmpdir(), 'pt-hub-e2e', 'public-ssr'),
    INERTIA_SSR_ENABLED: 'true',
    INERTIA_SSR_URL: `http://127.0.0.1:${ssrNodePort}`,
};

export default defineConfig({
    testDir: 'tests/e2e',
    fullyParallel: false,
    workers: 1,
    retries: process.env.CI ? 1 : 0,
    reporter: process.env.CI ? [['list'], ['html', { open: 'never' }]] : 'list',
    use: {
        baseURL,
        trace: 'retain-on-failure',
        screenshot: 'only-on-failure',
    },
    projects: [
        {
            name: 'chromium',
            use: { ...devices['Desktop Chrome'] },
            testIgnore: /ssr.spec.ts/,
        },
        {
            name: 'ssr',
            use: { ...devices['Desktop Chrome'], baseURL: ssrURL },
            testMatch: /ssr.spec.ts/,
        },
    ],
    webServer: [
        {
            command: 'node tests/e2e/serve.mjs',
            url: `${baseURL}/up`,
            timeout: 120_000,
            reuseExistingServer: false,
            env: appEnv,
        },
        {
            command: 'node bootstrap/ssr/app.js',
            url: `http://127.0.0.1:${ssrNodePort}/health`,
            timeout: 30_000,
            reuseExistingServer: false,
        },
        {
            command: 'node tests/e2e/serve.mjs',
            url: `${ssrURL}/up`,
            timeout: 120_000,
            reuseExistingServer: false,
            env: ssrEnv,
        },
    ],
});
