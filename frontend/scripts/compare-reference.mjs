import { chromium } from '@playwright/test';
import { readFileSync, mkdirSync } from 'node:fs';
import { fileURLToPath, URL } from 'node:url';
const root = fileURLToPath(new URL('../../', import.meta.url));
const browser = await chromium.launch({ channel: 'chrome' });
try {
    mkdirSync(`${root}/docs/assets/comparison`, { recursive: true });
    for (const width of [1440, 1280, 1024, 768, 390]) {
        const panelWidth = width < 768 ? 390 : 600;
        const panelHeight = width < 768 ? 844 : Math.round((panelWidth * 900) / 1440);
        const page = await browser.newPage({
            viewport: { width: panelWidth * 2 + 48, height: (panelHeight + 40) * 2 + 48 },
        });
        const panels = [
            ['Reference · Dashboard', `reference/${width}-dashboard.png`],
            ['MarketHub · Dashboard', `visual/${width}/dashboard.png`],
            ['Reference · Tasks', `reference/${width}-tasks.png`],
            ['MarketHub · Catalog', `visual/${width}/catalog.png`],
        ];
        await page.setContent(
            `<style>*{box-sizing:border-box}body{margin:16px;background:#f1f5f9;font:12px Arial;color:#0f172a}.grid{display:grid;grid-template-columns:1fr 1fr;gap:16px}section{background:white;border:1px solid #e2e8f0;border-radius:8px;overflow:hidden}h2{font-size:12px;padding:12px;margin:0;border-bottom:1px solid #e2e8f0}.frame{height:${panelHeight}px;overflow:hidden}img{display:block;width:100%;height:auto}</style><div class="grid">${panels.map(([label, path]) => `<section><h2>${label} · viewport ${width}px</h2><div class="frame"><img src="data:image/png;base64,${readFileSync(`${root}/docs/assets/${path}`).toString('base64')}"></div></section>`).join('')}</div>`,
        );
        await page.screenshot({
            path: `${root}/docs/assets/comparison/${width}.png`,
            fullPage: true,
        });
        await page.close();
    }
} finally {
    await browser.close();
}
