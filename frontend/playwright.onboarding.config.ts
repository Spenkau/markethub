import { defineConfig } from '@playwright/test';
import inventory from './playwright.inventory.config';
export default defineConfig({ ...inventory, testMatch: 'onboarding.spec.ts', timeout: 180000 });
