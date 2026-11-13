import { defineConfig } from '@playwright/test';
import inventory from './playwright.demo.config';
export default defineConfig({
    ...inventory,
    testMatch: [
        'app.spec.ts',
        'dashboard.spec.ts',
        'demo.spec.ts',
        'inventory.spec.ts',
        'onboarding.spec.ts',
        'procurement.spec.ts',
        'shopify.spec.ts',
    ],
});
