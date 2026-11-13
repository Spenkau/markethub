import { test, expect, type Page } from '@playwright/test';
import { database } from './support/demo-fixture';
test.beforeAll(() => database(true));
test.afterAll(() => database(false));

test('Demo CTA keeps its size while loading and reports a failed login', async ({ page }) => {
    for (const width of [1280, 375]) {
        await page.setViewportSize({ width, height: 844 });
        await page.goto('/');
        const button = page.getByRole('button', { name: 'Войти в демонстрационный аккаунт' });
        await expect(button).toBeVisible();
        await page.evaluate(async () => {
            await document.fonts.ready;
        });
        const before = await button.boundingBox();
        let release: (() => void) | undefined;
        await page.route('**/api/v1/auth/demo', async (route) => {
            if (route.request().method() !== 'POST') return route.continue();
            await new Promise<void>((resolve) => {
                release = resolve;
            });
            await route.fulfill({
                status: 503,
                json: { message: 'Демоверсия временно недоступна.' },
            });
        });
        await button.click();
        const loading = page.getByRole('button', { name: 'Открываем демоверсию…' });
        await expect(loading).toBeDisabled();
        await expect(loading).toHaveAttribute('aria-busy', 'true');
        expect(await loading.boundingBox()).toEqual(before);
        await expect.poll(() => !!release).toBe(true);
        release!();
        await expect(page.getByRole('alert')).toContainText(
            'Не удалось связаться с сервером. Попробуйте ещё раз.',
        );
        await expect(button).toBeEnabled();
        await expect(page).toHaveURL(/\/$/);
        await page.unroute('**/api/v1/auth/demo');
    }
});
async function enter(page: Page) {
    await page.goto('/');
    await page.getByRole('button', { name: 'Войти в демонстрационный аккаунт' }).click();
    await expect(page.getByTestId('demo-banner')).toBeVisible();
    // Demo tours use per-tab state, never a shared user's database preferences.
    await page.evaluate(() =>
        sessionStorage.setItem(
            'markethub-demo-tours',
            JSON.stringify({
                preferences: { auto_start: false, checklist_collapsed: false },
                progress: [],
            }),
        ),
    );
    await page.reload();
}
test('Demo login, readonly API, safe routes, dashboard and mobile', async ({ page }, info) => {
    const errors: string[] = [];
    page.on('pageerror', (e) => errors.push(e.message));
    await page.setViewportSize({ width: 390, height: 844 });
    await page.goto('/');
    await expect(
        page.getByRole('button', { name: 'Войти в демонстрационный аккаунт' }),
    ).toBeVisible();
    await expect(page.locator('body')).toContainText('JAROVIT');
    await expect(page.locator('body')).toContainText(
        'Покупательские заказы и финансы не реализованы',
    );
    expect(
        await page.evaluate(() => document.documentElement.scrollWidth <= window.innerWidth),
    ).toBe(true);
    await page.screenshot({ path: info.outputPath('landing-mobile.png'), fullPage: true });
    await page.setViewportSize({ width: 1280, height: 800 });
    await enter(page);
    await expect(page.locator('.dashboard-connection')).toHaveCount(3);
    const token = (await page.context().cookies()).find((c) => c.name === 'XSRF-TOKEN');
    expect(token).toBeTruthy();
    for (const path of [
        'products',
        'stock-operations',
        'purchase-orders',
        'goods-receipts',
        'suppliers',
        'organizations',
        'marketplace-accounts',
        'marketplace-connections/check',
        'marketplace-accounts/999/sync',
    ]) {
        const response = await page.request.post(`/api/v1/${path}`, {
            headers: {
                'X-XSRF-TOKEN': decodeURIComponent(token!.value),
                Accept: 'application/json',
            },
            data: {},
        });
        expect(response.status(), path).toBe(403);
    }
    for (const path of [
        '/app/products',
        '/app/stocks',
        '/app/purchases',
        '/app/purchases?tab=suppliers',
        '/app/marketplace-stocks',
        '/app/marketplaces',
        '/app/help',
    ]) {
        await page.goto(path);
        await expect(page.getByTestId('demo-banner')).toBeVisible();
        await expect(page.locator('[data-demo-write]:visible')).toHaveCount(0);
        await expect(page.locator('.page-state.error')).toHaveCount(0);
    }
    await page.goto('/app/products/new');
    await expect(page).toHaveURL(/\/app\/products$/);
    await page.goto('/app/marketplaces?connect=shopify');
    await expect(page.getByRole('dialog')).toHaveCount(0);
    await page.setViewportSize({ width: 390, height: 844 });
    await page.goto('/app');
    await expect(page.locator('.dashboard-kpis')).toBeVisible();
    await expect(page.getByLabel('Активная организация')).toBeAttached();
    await page.screenshot({ path: info.outputPath('demo-mobile.png'), fullPage: true });
    expect(
        await page.evaluate(() => document.documentElement.scrollWidth <= window.innerWidth),
    ).toBe(true);
    expect(errors).toEqual([]);
});
test('Catalog stock states, broken photo, zoom keyboard focus and local export', async ({
    page,
}) => {
    await enter(page);
    await page.goto('/app/products');
    await expect(page.locator('tbody tr').filter({ hasText: 'SYNTHETIC-3' })).toContainText(
        'MarketHub: 0 шт.',
    );
    await expect(page.locator('tbody tr').filter({ hasText: 'SYNTHETIC-4' })).toContainText(
        'MarketHub: Нет данных',
    );
    await expect(page.locator('tbody tr').filter({ hasText: 'SYNTHETIC-1' })).toContainText(
        'MarketHub: 8 шт.',
    );
    await expect(
        page.getByText('Все локальные товары организации, без фильтров списка.', { exact: false }),
    ).toBeVisible();
    const download = page.waitForEvent('download');
    await page.getByRole('button', { name: 'Экспорт локальных SKU' }).click();
    expect((await download).suggestedFilename()).toBe('products.xlsx');
    await page.route('**/demo-assets/product-1.svg', (route) =>
        route.fulfill({ status: 404, body: '' }),
    );
    await page
        .locator('tbody tr')
        .filter({ hasText: 'SYNTHETIC-1' })
        .getByRole('link', { name: 'Открыть →' })
        .click();
    const gallery = page.locator('.product-gallery').first();
    await expect(gallery.locator('.gallery-main .gallery-placeholder')).toBeVisible();
    const opener = gallery.locator('.gallery-main');
    await opener.click();
    const dialog = page.getByRole('dialog', { name: 'Просмотр изображения' });
    const close = dialog.getByRole('button', { name: 'Закрыть изображение' });
    await expect(close).toBeFocused();
    await page.keyboard.press('Shift+Tab');
    await expect(dialog.getByRole('button', { name: 'Далее', exact: true })).toBeFocused();
    await page.keyboard.press('Tab');
    await expect(close).toBeFocused();
    await expect(dialog.getByRole('img', { name: 'Изображение недоступно' })).toBeVisible();
    await page.keyboard.press('ArrowRight');
    await expect(dialog.locator('img')).toBeVisible();
    await page.keyboard.press('Escape');
    await expect(dialog).not.toBeVisible();
    await expect(opener).toBeFocused();
    await opener.click();
    await close.click();
    await expect(opener).toBeFocused();
    expect(
        await gallery
            .locator('img')
            .evaluateAll(
                (images) =>
                    images.filter((image) => !(image as HTMLImageElement).naturalWidth).length,
            ),
    ).toBe(0);
});
test('Demo tours remain usable without shared writes; synthetic connection history', async ({
    page,
}) => {
    await enter(page);
    const writes: string[] = [];
    page.on('request', (r) => {
        if (r.method() === 'PATCH' && r.url().includes('/onboarding/')) writes.push(r.url());
    });
    await page.getByRole('button', { name: 'Как это работает?', exact: true }).click();
    await expect(page.getByRole('button', { name: 'Далее', exact: true })).toBeVisible();
    await page.getByRole('button', { name: 'Далее', exact: true }).click();
    await page.getByRole('button', { name: 'Пропустить', exact: true }).click();
    expect(writes).toEqual([]);
    await page.goto('/app/marketplaces');
    await page
        .locator('.account-card')
        .filter({ hasText: 'Shopify-like' })
        .getByRole('link', { name: 'Открыть подключение', exact: true })
        .click();
    await expect(
        page.getByText(
            'Synthetic demo: товары и история созданы локально, Shopify API не вызывался.',
        ),
    ).toBeVisible();
    await page.getByRole('tab', { name: 'История', exact: true }).click();
    await expect(page.locator('tbody tr')).toHaveCount(1);
    await page.getByRole('tab', { name: 'Товары', exact: true }).click();
    await expect(page.locator('tbody tr')).toHaveCount(4);
});
