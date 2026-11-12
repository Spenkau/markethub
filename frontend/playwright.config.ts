import { defineConfig } from '@playwright/test';
import inventory from './playwright.inventory.config';
export default defineConfig({ ...inventory, testMatch: 'app.spec.ts' });
