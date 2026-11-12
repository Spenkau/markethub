import { defineConfig } from '@playwright/test';
export default defineConfig({
    testDir: './e2e',
    testMatch: 'inventory.spec.ts',
    workers: 1,
    timeout: 120000,
    expect: { timeout: 15000 },
    use: {
        baseURL: 'http://127.0.0.1:5174',
        channel: 'chrome',
        headless: true,
        actionTimeout: 15000,
    },
    webServer: [
        {
            command: 'php artisan serve --env=testing --host=127.0.0.1 --port=8001',
            cwd: '..',
            url: 'http://127.0.0.1:8001/up',
            reuseExistingServer: false,
            env: {
                APP_ENV: 'testing',
                SESSION_DRIVER: 'file',
                CACHE_STORE: 'array',
                QUEUE_CONNECTION: 'sync',
            },
        },
        {
            command: 'npm run dev -- --port 5174',
            url: 'http://127.0.0.1:5174',
            reuseExistingServer: false,
            env: { MARKETHUB_API_TARGET: 'http://127.0.0.1:8001' },
        },
    ],
    reporter: 'list',
});
