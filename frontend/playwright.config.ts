import { defineConfig } from '@playwright/test';
export default defineConfig({
    testDir: './e2e',
    testIgnore: ['inventory.spec.ts', 'procurement.spec.ts', 'onboarding.spec.ts'],
    workers: 1,
    timeout: 120000,
    expect: { timeout: 15000 },
    use: {
        baseURL: 'http://127.0.0.1:5173',
        channel: 'chrome',
        headless: true,
        actionTimeout: 15000,
    },
    reporter: 'list',
});
