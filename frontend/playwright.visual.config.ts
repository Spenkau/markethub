import { defineConfig } from '@playwright/test';
import demo from './playwright.demo.config';
export default defineConfig({ ...demo, testMatch: 'release-visual.spec.ts', timeout: 900000 });
