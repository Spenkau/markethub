import { defineConfig } from '@playwright/test';
import inventory from './playwright.inventory.config';
export default defineConfig({ ...inventory, testMatch: 'dashboard.spec.ts', timeout: 60000 });
