import { test, expect } from '@playwright/test';
import { mkdirSync, writeFileSync } from 'node:fs';
import { database, fixturePassword } from './support/demo-fixture';

const viewports = [
    [1440, 900],
    [1280, 800],
    [1024, 768],
    [768, 1024],
    [390, 844],
    [375, 812],
].filter(
    ([width]) =>
        !process.env.VISUAL_WIDTHS || process.env.VISUAL_WIDTHS.split(',').includes(String(width)),
);
test.beforeAll(() => database(true));
test.afterAll(() => database(false));
test('release screenshot matrix with synthetic data', async ({ page }) => {
    const errors: string[] = [];
    page.on('pageerror', (e) => errors.push(e.message));
    page.on('console', (e) => {
        if (e.type() === 'error') errors.push(`${e.text()} ${e.location().url}`);
    });
    await page.goto('/');
    await page.getByRole('button', { name: 'Войти в демонстрационный аккаунт' }).click();
    await expect(page.getByTestId('demo-banner')).toBeVisible();
    await page.evaluate(() =>
        sessionStorage.setItem(
            'markethub-demo-tours',
            JSON.stringify({
                preferences: { auto_start: false, checklist_collapsed: true },
                progress: [],
            }),
        ),
    );
    await page.setViewportSize({ width: 1440, height: 900 });
    await page.goto('/app');
    await expect(page.locator('.dashboard-connection')).toHaveCount(3);
    await page.screenshot({ path: 'public/demo-assets/workspace-preview.png' });
    const routes = {
        dashboard: '/app',
        catalog: '/app/products',
        product: '/app/products/1',
        marketplaces: '/app/marketplaces',
        connection: '/app/marketplaces/3',
        'connection-products': '/app/marketplaces/3?tab=products',
        'connection-inventory': '/app/marketplaces/3?tab=inventory',
        'connection-history': '/app/marketplaces/3?tab=history',
        'connection-settings': '/app/marketplaces/3?tab=settings',
        'external-product': '/app/catalog/external_variant/1',
        'marketplace-stocks': '/app/marketplace-stocks',
        stocks: '/app/stocks',
        warehouses: '/app/stocks?tab=warehouses',
        movements: '/app/stocks?tab=movements',
        purchases: '/app/purchases',
        suppliers: '/app/purchases?tab=suppliers',
        receipts: '/app/purchases?tab=receipts',
        purchase: '/app/purchases/1',
        receipt: '/app/purchases/receipts/1',
        help: '/app/help',
    };
    const geometry: unknown[] = [];
    for (const [width, height] of viewports) {
        await page.setViewportSize({ width: width!, height: height! });
        const dir = `../docs/assets/visual/${width}`;
        mkdirSync(dir, { recursive: true });
        for (const [name, route] of Object.entries(routes)) {
            await page.goto(route);
            await page.waitForLoadState('networkidle');
            await expect(page.locator('.page-state.error')).toHaveCount(0);
            const overflow = await page.evaluate(() =>
                Array.from(document.querySelectorAll('body *'))
                    .filter(
                        (el) =>
                            el.getBoundingClientRect().right > innerWidth + 1 &&
                            !el.closest(
                                '.table-scroll, .table-wrap, .tabs, .inventory-tabs, .sidebar',
                            ),
                    )
                    .slice(0, 8)
                    .map(
                        (el) =>
                            `${el.tagName}.${el.className}: ${el.getBoundingClientRect().right}`,
                    ),
            );
            await page.screenshot({ path: `${dir}/${name}.png`, fullPage: true });
            expect(
                await page.evaluate(() => document.documentElement.scrollWidth <= innerWidth),
                `${width}: ${name} overflow ${overflow.join('; ')}`,
            ).toBe(true);
            expect(
                await page
                    .locator('img')
                    .evaluateAll((images) =>
                        images.filter((i) => !i.complete || !i.naturalWidth).map((i) => i.src),
                    ),
                `${width}: ${name} broken images`,
            ).toEqual([]);
            if (name === 'dashboard')
                geometry.push(
                    await page.evaluate(() => ({
                        width: innerWidth,
                        header: document.querySelector('.topbar')?.getBoundingClientRect().height,
                        sidebar: document.querySelector('.sidebar')?.getBoundingClientRect().width,
                        font: getComputedStyle(document.body).fontFamily,
                    })),
                );
        }
        await page.goto('/app/products/1');
        await page.locator('.gallery-main').first().click();
        await expect(page.getByRole('dialog', { name: 'Просмотр изображения' })).toBeVisible();
        await page.screenshot({ path: `${dir}/gallery.png` });
        await page.keyboard.press('Escape');
        await page.goto('/app');
        await page.getByRole('button', { name: 'Как это работает?', exact: true }).click();
        await expect(page.getByRole('button', { name: 'Далее', exact: true })).toBeVisible();
        await page.screenshot({ path: `${dir}/tour.png` });
        await page.getByRole('button', { name: 'Пропустить', exact: true }).click();
        if (width! < 768) {
            const opener = page.getByRole('button', { name: 'Открыть меню', exact: true });
            await opener.click();
            await expect(page.getByLabel('Организация в мобильном меню')).toBeVisible();
            await page.screenshot({ path: `${dir}/navigation.png` });
            await page.keyboard.press('Escape');
            await expect(opener).toBeFocused();
        } else {
            const toggle = page.getByRole('button', { name: 'Свернуть или развернуть меню' });
            await toggle.click();
            await expect(page.locator('.sidebar')).toHaveCSS('width', '72px');
            await page.screenshot({ path: `${dir}/navigation.png` });
            await toggle.click();
        }
        await page.context().clearCookies();
        await page.goto('/');
        await page.waitForLoadState('networkidle');
        await page.screenshot({ path: `${dir}/landing.png`, fullPage: true });
        await page.getByRole('button', { name: 'Войти в демонстрационный аккаунт' }).click();
        await expect(page.getByTestId('demo-banner')).toBeVisible();
    }
    writeFileSync('../docs/assets/visual/geometry.json', JSON.stringify(geometry, null, 2));
    expect(errors).toEqual([]);
});

test('writable forms, dialogs and keyboard focus on synthetic data', async ({ page }) => {
    database(true, true);
    const errors: string[] = [];
    page.on('pageerror', (e) => errors.push(e.message));
    await page.goto('/login');
    await page.getByLabel('Email', { exact: true }).fill('demo@example.invalid');
    await page.getByLabel('Пароль', { exact: true }).fill(fixturePassword);
    await page.getByRole('button', { name: 'Войти в кабинет' }).click();
    await expect(page).toHaveURL(/\/app$/);
    for (const [width, height] of viewports) {
        await page.setViewportSize({ width: width!, height: height! });
        const dir = `../docs/assets/visual/${width}`;
        mkdirSync(dir, { recursive: true });
        for (const [name, route] of Object.entries({
            'product-create': '/app/products/new',
            'product-edit': '/app/products/1/edit',
            'connection-form': '/app/marketplaces?connect=shopify',
            'warehouse-form': '/app/stocks',
            'supplier-form': '/app/purchases?tab=suppliers',
            'purchase-form': '/app/purchases?create=purchase',
        })) {
            await page.goto(route);
            await page.waitForLoadState('networkidle');
            if (name === 'warehouse-form')
                await page.getByRole('button', { name: 'Новый склад', exact: true }).click();
            if (name === 'supplier-form')
                await page.getByRole('button', { name: 'Новый поставщик', exact: true }).click();
            if (name.endsWith('-form')) await expect(page.getByRole('dialog')).toBeVisible();
            expect(
                await page.evaluate(() => document.documentElement.scrollWidth <= innerWidth),
                `${width}: ${name}`,
            ).toBe(true);
            await page.screenshot({ path: `${dir}/${name}.png`, fullPage: true });
        }
        await page.goto('/app/stocks');
        await page.getByRole('button', { name: 'Поступление', exact: true }).click();
        const dialog = page.getByRole('dialog');
        await expect(dialog).toBeVisible();
        await expect(dialog.locator('input,select,button').first()).toBeFocused();
        await page.screenshot({ path: `${dir}/stock-operation.png`, fullPage: true });
        await page.keyboard.press('Escape');
        await expect(dialog).not.toBeVisible();
        await expect(page.getByRole('button', { name: 'Поступление', exact: true })).toBeFocused();
        await page.goto('/app/purchases/1');
        await page.getByRole('button', { name: 'Создать приёмку', exact: true }).click();
        await expect(page.getByRole('dialog')).toBeVisible();
        await page.screenshot({ path: `${dir}/receipt-form.png`, fullPage: true });
        await page.goto('/app/products/1');
        await page.getByRole('button', { name: 'Сопоставить с MarketHub', exact: true }).click();
        await expect(page.getByRole('dialog')).toBeVisible();
        await page.waitForLoadState('networkidle');
        await page.screenshot({ path: `${dir}/mapping.png`, fullPage: true });
        await page.goto('/app/products');
        const remove = page.getByRole('button', { name: 'Удалить товар', exact: true }).first();
        await remove.click();
        const confirmation = page.getByRole('dialog', { name: 'Удаление товара' });
        const cancel = confirmation.getByRole('button', { name: 'Отмена', exact: true });
        await expect(cancel).toBeFocused();
        await page.keyboard.press('Shift+Tab');
        await expect(
            confirmation.getByRole('button', { name: 'Удалить', exact: true }),
        ).toBeFocused();
        await page.keyboard.press('Tab');
        await expect(cancel).toBeFocused();
        await page.screenshot({ path: `${dir}/confirmation.png`, fullPage: true });
        await page.keyboard.press('Escape');
        await expect(confirmation).not.toBeVisible();
        await expect(remove).toBeFocused();
    }
    expect(errors).toEqual([]);
});
