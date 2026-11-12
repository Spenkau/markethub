import { test, expect } from '@playwright/test';
import type { ShopifySummary, SyncRun } from '../src/api/shopify';

test('первый импорт требует подтверждения, результаты и настройки Shopify доступны', async ({
    page,
}) => {
    const summary: ShopifySummary = {
        domain: 'test.myshopify.com',
        api_status: 'connected',
        api_checked_at: null,
        currency: 'USD',
        sync_frequency: 'hourly',
        sync_products: true,
        sync_inventory: true,
        initial_import_at: null,
        last_synced_at: null,
        next_sync_at: null,
        products_count: 1,
        variants_count: 1,
        unmapped_count: 1,
        latest_run: null,
    };
    const run: SyncRun = {
        id: 'test-run',
        status: 'completed',
        trigger: 'manual',
        sync_products: true,
        sync_inventory: true,
        full_reconcile: true,
        processed: 2,
        products_processed: 1,
        variants_processed: 1,
        inventory_processed: 1,
        created: 1,
        updated: 0,
        skipped: 0,
        errors_count: 0,
        error_message: null,
        details: [],
        created_at: '2026-11-12T10:00:00Z',
        started_at: '2026-11-12T10:00:01Z',
        finished_at: '2026-11-12T10:00:03Z',
    };
    const account = {
        id: 1,
        name: 'Shopify Test',
        marketplace: 'shopify',
        status: 'active',
        is_demo: false,
        shopify: summary,
    };
    let pointCalls = 0;
    let syncCalls = 0,
        mappingCalls = 0;
    function list(data: unknown[]) {
        return {
            data,
            meta: { total: data.length, current_page: 1, last_page: 1, per_page: 20 },
            links: { first: '', last: '', prev: null, next: null },
        };
    }
    await page.route('**/sanctum/csrf-cookie', (route) => route.fulfill({ status: 204 }));
    await page.route('**/api/v1/**', async (route) => {
        const request = route.request(),
            url = new URL(request.url()),
            path = url.pathname;
        if (path.endsWith('/auth/me'))
            return route.fulfill({
                json: { data: { id: 1, name: 'User', active_organization_id: 1 } },
            });
        if (path.endsWith('/organizations'))
            return route.fulfill({ json: list([{ id: 1, name: 'Org' }]) });
        if (path.endsWith('/onboarding'))
            return route.fulfill({
                json: {
                    data: {
                        preferences: { auto_start: false, checklist_collapsed: false },
                        progress: [],
                    },
                },
            });
        if (path.endsWith('/sync')) {
            syncCalls++;
            expect(request.postDataJSON().confirm_initial_import).toBe(true);
            summary.initial_import_at = run.finished_at;
            summary.last_synced_at = run.finished_at;
            summary.latest_run = run;
            return route.fulfill({ status: 202, json: { data: run } });
        }
        if (path.endsWith('/mapping')) {
            mappingCalls++;
            expect(request.postDataJSON().product_id).toBe(10);
            return route.fulfill({ status: 204 });
        }
        if (path.endsWith('/connections/1') && request.method() === 'PATCH') {
            const data = request.postDataJSON();
            Object.assign(summary, data);
            expect(data.sync_frequency).toBe('daily');
            expect(data.sync_products).toBe(false);
            expect(data.sync_inventory).toBe(true);
            return route.fulfill({ json: { data: account } });
        }
        if (path.endsWith('/marketplace-accounts')) return route.fulfill({ json: list([]) });
        if (path.endsWith('/connections') && request.method() === 'POST') {
            expect(request.postDataJSON().shop_domain).toBe('test.myshopify.com');
            return route.fulfill({ status: 201, json: { data: account } });
        }
        if (path.endsWith('/products/3/refresh')) {
            pointCalls++;
            return route.fulfill({ status: 202, json: { data: run } });
        }
        if (path.endsWith('/products/3'))
            return route.fulfill({
                json: {
                    data: {
                        id: 3,
                        product_id: null,
                        source_status: 'active',
                        external_sku: null,
                        stock_quantity: null,
                        price_minor: pointCalls ? 2400 : 1234,
                        currency: 'USD',
                        source_synced_at: run.finished_at,
                        admin_url: 'https://test.myshopify.com/admin/products/2',
                        levels: [],
                        source_data: {
                            product: { id: 'product-2', title: 'Board', images: [] },
                            variant: { title: 'Default Title', compareAtPrice: null },
                        },
                    },
                },
            });
        if (path.endsWith('/connections/1')) return route.fulfill({ json: { data: account } });
        if (path.endsWith('/connections/1/products'))
            return route.fulfill({
                json: list([
                    {
                        id: 3,
                        product_id: null,
                        source_status: 'active',
                        external_sku: null,
                        price_minor: 1234,
                        currency: 'USD',
                        source_synced_at: run.finished_at,
                        admin_url: 'https://test.myshopify.com/admin/products/2',
                        source_data: {
                            product: { id: 'product-2', title: 'Board', images: [] },
                            variant: { title: 'Default Title', compareAtPrice: null },
                        },
                    },
                ]),
            });
        if (path.endsWith('/products')) {
            const second = url.searchParams.get('page') === '2';
            const response = list([
                {
                    id: second ? 11 : 10,
                    name: second ? 'Second Board' : 'Local Board',
                    sku: second ? 'LOCAL-2' : 'LOCAL-1',
                },
            ]);
            response.meta.current_page = second ? 2 : 1;
            response.meta.last_page = 2;
            response.meta.total = 21;
            return route.fulfill({ json: response });
        }
        if (path.endsWith('/locations'))
            return route.fulfill({ json: { data: [{ id: 1, name: 'Shop location' }] } });
        if (path.endsWith('/inventory'))
            return route.fulfill({
                json: list([
                    {
                        id: 1,
                        title: 'Board',
                        location_name: 'Shop location',
                        tracked: false,
                        available: null,
                        on_hand: null,
                        incoming: null,
                        committed: null,
                        source_synced_at: run.finished_at,
                        is_current: true,
                    },
                ]),
            });
        if (path.endsWith('/runs')) return route.fulfill({ json: list([run]) });
        if (path.endsWith('/runs/test-run')) return route.fulfill({ json: { data: run } });
        return route.fulfill({ json: { data: [] } });
    });
    await page.goto('/app/marketplaces');
    await expect(page.getByRole('link', { name: 'Подключить Shopify', exact: true })).toHaveCount(
        0,
    );
    await page.getByRole('button', { name: 'Добавить подключение' }).click();
    const connectionDialog = page.getByRole('dialog');
    await connectionDialog.getByLabel('Маркетплейс', { exact: true }).selectOption('shopify');
    await connectionDialog.getByLabel('Название', { exact: true }).fill('Shopify Test');
    await connectionDialog.getByLabel('Домен магазина').fill('test.myshopify.com');
    await connectionDialog.getByLabel('Client ID', { exact: true }).fill('test-client');
    await connectionDialog.getByLabel('Client Secret', { exact: true }).fill('test-secret');
    await connectionDialog.getByRole('button', { name: 'Сохранить', exact: true }).click();
    await expect(page).toHaveURL(/shopify\/1$/);
    await page.getByRole('button', { name: 'Выполнить первый импорт' }).click();
    expect(syncCalls).toBe(0);
    await expect(page.getByRole('dialog')).toContainText('Физические запасы не изменятся');
    await page.getByRole('button', { name: 'Подтвердить импорт' }).click();
    await expect(page.getByRole('dialog')).not.toBeVisible();
    expect(syncCalls).toBe(1);
    const navigation = page.getByRole('navigation', { name: 'Разделы Shopify' });
    await navigation.getByRole('link', { name: 'Товары', exact: true }).click();
    await expect(page.locator('tbody')).toContainText('Без SKU');
    await page.getByRole('button', { name: 'Сопоставить с каталогом', exact: true }).click();
    const mappingDialog = page.getByRole('dialog');
    await expect(
        mappingDialog.getByRole('button', { name: 'Local Board · LOCAL-1' }),
    ).toBeVisible();
    await mappingDialog.getByRole('button', { name: 'Далее →', exact: true }).click();
    await expect(
        mappingDialog.getByRole('button', { name: 'Second Board · LOCAL-2' }),
    ).toBeVisible();
    await mappingDialog.getByRole('button', { name: '← Назад', exact: true }).click();
    await page.getByRole('button', { name: 'Local Board · LOCAL-1', exact: true }).click();
    await page.getByRole('button', { name: 'Сохранить сопоставление' }).click();
    await expect(page.getByRole('dialog')).not.toBeVisible();
    expect(mappingCalls).toBe(1);
    await navigation.getByRole('link', { name: 'Остатки', exact: true }).click();
    await expect(page.locator('tbody')).toContainText('Учёт отключён в Shopify');
    await expect(page.locator('tbody tr td').nth(2)).toHaveText('—');
    await navigation.getByRole('link', { name: 'Настройки', exact: true }).click();
    await page.getByLabel('Периодичность').selectOption('daily');
    await page.getByLabel('Синхронизировать товары', { exact: true }).uncheck();
    await page.getByRole('button', { name: 'Сохранить настройки' }).click();
    await expect(page.getByRole('status').filter({ hasText: 'Настройки сохранены' })).toBeVisible();
    await navigation.getByRole('link', { name: 'История', exact: true }).click();
    await page.getByRole('button', { name: 'Детали', exact: true }).click();
    await expect(page.getByRole('dialog')).toContainText('Полная сверка');
    await page.getByRole('button', { name: 'Закрыть', exact: true }).click();
    await page.goto('/app/marketplaces/shopify/1/products/3');
    await page.getByRole('button', { name: 'Обновить из Shopify', exact: true }).click();
    await expect(
        page.getByRole('status').filter({ hasText: 'Свежие данные Shopify получены.' }),
    ).toBeVisible();
    expect(pointCalls).toBe(1);
    await expect(page.locator('.details')).toContainText('24');
    await page.setViewportSize({ width: 390, height: 844 });
    await expect(page.getByRole('heading', { name: 'Board', exact: true })).toBeVisible();
    expect(
        await page.evaluate(() => document.documentElement.scrollWidth <= window.innerWidth),
    ).toBe(true);
});
