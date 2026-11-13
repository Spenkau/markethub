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
            command:
                `"${process.env.PHP_BINARY || 'php'}" -S 127.0.0.1:8001 ../vendor/laravel/framework/src/Illuminate/Foundation/resources/server.php`,
            cwd: '../public',
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
            command: 'node node_modules/vite/bin/vite.js --port 5174 --strictPort',
            url: 'http://127.0.0.1:5174',
            reuseExistingServer: false,
            env: { MARKETHUB_API_TARGET: 'http://127.0.0.1:8001' },
        },
    ],
    reporter: 'list',
});
