import { defineConfig } from '@playwright/test';
import inventory from './playwright.inventory.config';
export default defineConfig({
    ...inventory,
    testMatch: 'demo.spec.ts',
    webServer: (Array.isArray(inventory.webServer)
        ? inventory.webServer
        : [inventory.webServer!]
    ).map((server, index) =>
        index === 0
            ? {
                  ...server,
                  env: {
                      ...server.env,
                      PUBLIC_DEMO_ENABLED: 'true',
                      PUBLIC_DEMO_DATABASE: 'markethub_api_test',
                  },
              }
            : server,
    ),
});
