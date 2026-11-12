import { test, expect, type Page } from '@playwright/test';

async function fixture(page: Page, variants = 2) {
    const list = (data: unknown[], total = data.length, current = 1, last = 1) => ({
        data,
        meta: { total, current_page: current, last_page: last, per_page: 20 },
        links: { first: '', last: '', prev: null, next: null },
    });
    const provider = {
        code: 'shopify',
        name: 'Shopify',
        connection_type: 'app_credentials',
        capabilities: [
            'products',
            'inventory',
            'locations',
            'sync',
            'check_connection',
            'refresh_listing',
        ],
        configuration_schema: [{ key: 'shop_domain', type: 'string', required: true }],
        credentials_schema: [
            { key: 'client_id', type: 'string', required: true },
            { key: 'client_secret', type: 'password', required: true },
        ],
    };
    const run = {
        id: 'run-1',
        status: 'running',
        scope: 'variant',
        target_listing_id: 103,
        processed: 0,
        errors_count: 0,
        error_message: null,
        created_at: '2026-11-12T10:00:00Z',
        finished_at: null,
    };
    const account = {
        id: 15,
        name: 'Shopify Test',
        marketplace: 'shopify',
        status: 'active',
        is_demo: false,
        configuration: { shop_domain: 'test.myshopify.com' },
        provider,
        capabilities: provider.capabilities,
        integration: {
            api_status: 'connected',
            api_checked_at: null,
            sync_frequency: 'manual',
            sync_products: true,
            sync_inventory: true,
            initial_import_at: '2026-11-12T10:00:00Z',
            last_synced_at: '2026-11-12T10:00:00Z',
            next_sync_at: null,
            products_count: 1,
            variants_count: 2,
            unmapped_count: 2,
            latest_run: null as null | typeof run,
        },
    };
    let refreshing = false,
        finished = false,
        price = 1200,
        mapped: number | null = null;
    const mutations: { path: string; body: Record<string, unknown> }[] = [];
    function source(id = 103) {
        return {
            id,
            product_id: id === 103 ? mapped : null,
            marketplace_product_id: 17,
            title: 'External Board',
            variant_title: id === 103 ? 'Blue / M' : 'Red / L',
            sku: `EXT-${id}`,
            barcode: '12345',
            external_id: `gid://shopify/ProductVariant/${id}`,
            origin: 'imported',
            status: 'active',
            price_minor: price,
            currency: 'USD',
            source_synced_at: '2026-11-12T10:00:00Z',
            source_updated_at: null,
            account: {
                id: 15,
                name: 'Shopify Test',
                provider: 'shopify',
                provider_name: 'Shopify',
                is_demo: false,
            },
            capabilities: provider.capabilities,
            admin_url: 'https://test.myshopify.com/admin/products/99',
            active_run: refreshing && !finished && id === run.target_listing_id ? run : null,
            images: [{ url: 'https://example.test/board.png', alt: 'Board' }],
            inventory: {
                quantity: 7,
                tracked: true,
                current: true,
                levels: 1,
                synced_at: '2026-11-12T10:00:00Z',
                origin: 'external_snapshot',
            },
        };
    }
    await page.route('https://example.test/**', (r) =>
        r.fulfill({
            contentType: 'image/svg+xml',
            body: '<svg xmlns="http://www.w3.org/2000/svg" width="100" height="100"><rect width="100" height="100" fill="blue"/></svg>',
        }),
    );
    await page.route('**/api/v1/**', async (route) => {
        const request = route.request(),
            url = new URL(request.url()),
            path = url.pathname;
        if (['POST', 'PATCH'].includes(request.method()))
            mutations.push({ path, body: request.postDataJSON() || {} });
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
                        preferences: { auto_start: false, checklist_collapsed: true },
                        progress: [],
                    },
                },
            });
        if (path.endsWith('/marketplace-providers'))
            return route.fulfill({
                json: {
                    data: [
                        provider,
                        {
                            code: 'ozon',
                            name: 'Ozon',
                            connection_type: 'demo',
                            capabilities: [],
                            configuration_schema: [],
                            credentials_schema: [],
                        },
                    ],
                },
            });
        if (path.endsWith('/marketplace-connections/check'))
            return route.fulfill({ json: { data: { missing_scopes: [] } } });
        if (path.endsWith('/marketplace-accounts') && request.method() === 'POST')
            return route.fulfill({ status: 201, json: { data: account } });
        if (path.endsWith('/marketplace-accounts')) return route.fulfill({ json: list([account]) });
        if (path.endsWith('/marketplace-accounts/15') && request.method() === 'PATCH') {
            Object.assign(account.integration, request.postDataJSON());
            return route.fulfill({ json: { data: account } });
        }
        if (path.endsWith('/marketplace-accounts/15'))
            return route.fulfill({ json: { data: account } });
        if (path.endsWith('/check'))
            return route.fulfill({ json: { data: { missing_scopes: [] } } });
        if (path.endsWith('/sync')) {
            expect(request.postDataJSON().confirm_initial_import).toBe(true);
            account.integration.initial_import_at = '2026-11-12T10:00:00Z';
            return route.fulfill({ status: 202, json: { data: { ...run, scope: 'account' } } });
        }
        if (path.endsWith('/refresh')) {
            run.target_listing_id = Number(path.split('/').at(-2));
            refreshing = true;
            return route.fulfill({ status: 202, json: { data: run } });
        }
        if (path.endsWith('/sync-runs/run-1')) {
            if (finished) price = 2400;
            return route.fulfill({
                json: {
                    data: {
                        ...run,
                        status: finished ? 'completed' : 'running',
                        processed: finished ? 2 : 0,
                    },
                },
            });
        }
        if (path.endsWith('/sync-status'))
            return route.fulfill({
                json: { data: { active_run: null, latest_run: { ...run, status: 'completed' } } },
            });
        if (path.endsWith('/sync-runs'))
            return route.fulfill({ json: list([{ ...run, status: 'completed', processed: 2 }]) });
        if (path.endsWith('/mapping')) {
            mapped = request.postDataJSON().product_id;
            return route.fulfill({ status: 204 });
        }
        if (path.endsWith('/local-product')) {
            if (request.postDataJSON().sku === 'DUPLICATE')
                return route.fulfill({
                    status: 422,
                    json: { message: 'Validation', errors: { sku: ['SKU уже существует.'] } },
                });
            mapped = 99;
            return route.fulfill({ status: 201, json: { data: { id: 99 } } });
        }
        if (path.endsWith('/products')) {
            const n = Number(url.searchParams.get('page') || 1);
            return route.fulfill({
                json: list(
                    Array.from({ length: n === 1 ? 20 : 5 }, (_, i) => ({
                        id: (n - 1) * 20 + i + 1,
                        name: `Local ${String((n - 1) * 20 + i + 1).padStart(2, '0')}`,
                        sku: `SKU-${(n - 1) * 20 + i + 1}`,
                    })),
                    25,
                    n,
                    2,
                ),
            });
        }
        if (path.endsWith('/catalog'))
            return route.fulfill({
                json: list([
                    {
                        key: 'external_product:17',
                        kind: 'external_product',
                        entity_id: 17,
                        title: 'External Board',
                        sku: 'EXT-103',
                        price_minor: price,
                        currency: 'USD',
                        status: 'active',
                        mapping: 'unmapped',
                        images: source().images,
                        sources: [source(), source(104)],
                        sources_count: 2,
                        physical_quantity: null,
                        source_synced_at: '2026-11-12T10:00:00Z',
                    },
                ]),
            });
        if (path.includes('/catalog/')) {
            const variant = path.includes('external_variant');
            const currentPage = Number(url.searchParams.get('page') || 1);
            const items = Array.from({ length: variants }, (_, i) => source(103 + i)).slice(
                (currentPage - 1) * 20,
                currentPage * 20,
            );
            return route.fulfill({
                json: {
                    data: {
                        key: variant ? 'external_variant:103' : 'external_product:17',
                        kind: variant ? 'external_variant' : 'external_product',
                        entity_id: variant ? 103 : 17,
                        title: 'External Board',
                        sku: 'EXT-103',
                        description: 'External description',
                        images: source().images,
                        local_product: null,
                        sources: variant ? [source(Number(path.split('/').at(-1)))] : items,
                        sources_count: variant ? 1 : variants,
                        sources_page: currentPage,
                        sources_last_page: variant ? 1 : Math.ceil(variants / 20),
                        physical_stocks: [],
                        inventory: [
                            {
                                id: 1,
                                marketplace_listing_id: 103,
                                location_name: 'Main',
                                available: 7,
                                tracked: true,
                                is_current: true,
                                source_synced_at: '2026-11-12T10:00:00Z',
                                location_active: true,
                            },
                        ],
                        inventory_total: 1,
                    },
                },
            });
        }
        if (path.endsWith('/stocks'))
            return route.fulfill({
                json: list([
                    {
                        id: 103,
                        marketplace_account_id: 15,
                        marketplace_account: account,
                        product: null,
                        source_title: 'External Board',
                        external_sku: 'EXT-103',
                        stock_quantity: 7,
                        price_minor: 1200,
                        currency: 'USD',
                        status: 'active',
                        inventory_level_id: 1,
                        marketplace_location_id: 11,
                        location_name: 'Main',
                        tracked: true,
                        is_current: true,
                    },
                ]),
            });
        if (path.endsWith('/locations'))
            return route.fulfill({
                json: {
                    data: [
                        {
                            id: 11,
                            marketplace_account_id: 15,
                            name: 'Main',
                            is_active: true,
                            warehouse_id: null,
                        },
                    ],
                },
            });
        if (path.endsWith('/warehouses')) return route.fulfill({ json: list([]) });
        return route.fulfill({ json: { data: [] } });
    });
    return {
        mutations,
        account,
        finish: () => {
            finished = true;
            price = 2400;
        },
    };
}

test('общий каталог, галерея, refresh выбранного варианта и восстановление после reload', async ({
    page,
}) => {
    const f = await fixture(page);
    await page.goto('/app/products?marketplace=shopify');
    await expect(page.getByRole('heading', { name: 'Товары', exact: true })).toBeVisible();
    await expect(page.locator('tbody tr')).toHaveCount(1);
    await page.getByLabel('Увеличить изображение: External Board').click();
    await expect(page.getByRole('dialog', { name: 'Просмотр изображения' })).toBeVisible();
    await page.getByRole('button', { name: 'Закрыть изображение' }).click();
    await page.getByRole('link', { name: 'External Board', exact: true }).click();
    await expect(page).toHaveURL(/catalog\/external_product\/17/);
    await expect(page.locator('.source-card')).toHaveCount(2);
    const first = page.locator('[data-variant="103"]');
    await expect(first.getByRole('link', { name: 'Открыть в Shopify Admin' })).toHaveAttribute(
        'href',
        'https://test.myshopify.com/admin/products/99',
    );
    await first.getByRole('button', { name: 'Обновить из источника' }).click();
    await expect(first.getByRole('button', { name: 'Обновляется…' })).toBeDisabled();
    await page.reload();
    await expect(
        page.locator('[data-variant="103"]').getByRole('button', { name: 'Обновляется…' }),
    ).toBeDisabled();
    f.finish();
    await expect(
        page.getByRole('status').filter({ hasText: 'обновлён из источника' }),
    ).toBeVisible();
    await expect(page.locator('[data-variant="103"]')).toContainText('24,00 USD');
    await page.getByRole('link', { name: '← К списку товаров' }).click();
    await expect(page).toHaveURL(/marketplace=shopify/);
    await page.setViewportSize({ width: 390, height: 844 });
    expect(await page.evaluate(() => document.documentElement.scrollWidth)).toBeLessThanOrEqual(
        390,
    );
});

test('сопоставление конкретной SKU, пагинация ResourcePicker, отмена и явное создание', async ({
    page,
}) => {
    const f = await fixture(page);
    await page.goto('/app/catalog/external_variant/103');
    await page.getByRole('button', { name: 'Сопоставить с MarketHub', exact: true }).click();
    const dialog = page.getByRole('dialog', { name: 'Сопоставить с MarketHub' });
    await dialog.getByRole('button', { name: 'Далее →', exact: true }).click();
    await expect(
        dialog.getByRole('button', { name: 'Local 25 · SKU-25', exact: true }),
    ).toBeVisible();
    await dialog.getByRole('button', { name: '← Назад', exact: true }).click();
    await expect(
        dialog.getByRole('button', { name: 'Local 01 · SKU-1', exact: true }),
    ).toBeVisible();
    await dialog.getByRole('button', { name: 'Далее →', exact: true }).click();
    await dialog.getByRole('button', { name: 'Local 25 · SKU-25', exact: true }).click();
    await dialog.getByRole('button', { name: 'Сопоставить', exact: true }).click();
    await expect(
        page.getByRole('status').filter({ hasText: 'Сопоставление сохранено' }),
    ).toBeVisible();
    expect(f.mutations.find((m) => m.path.endsWith('/mapping'))?.body.product_id).toBe(25);
    await page.getByRole('button', { name: 'Отменить сопоставление', exact: true }).click();
    await expect(page.getByRole('status').filter({ hasText: 'отменено' })).toBeVisible();
    await page.getByRole('button', { name: 'Сопоставить с MarketHub', exact: true }).click();
    await page
        .getByRole('dialog', { name: 'Сопоставить с MarketHub' })
        .getByRole('combobox')
        .first()
        .selectOption('create');
    await page.getByLabel('SKU нового товара').fill('DUPLICATE');
    await page.getByRole('button', { name: 'Создать и сопоставить' }).click();
    await expect(page.getByText('SKU уже существует.')).toBeVisible();
    await page.getByLabel('SKU нового товара').fill('NEW-SKU');
    await page.getByRole('button', { name: 'Создать и сопоставить' }).click();
    await expect(
        page.getByRole('status').filter({ hasText: 'создан и сопоставлен' }),
    ).toBeVisible();
});

test('одна форма подключения, проверка до сохранения и общие настройки manual/hourly/daily', async ({
    page,
}) => {
    const f = await fixture(page);
    await page.goto('/app/marketplaces');
    await page.getByRole('button', { name: 'Добавить подключение', exact: true }).click();
    const dialog = page.getByRole('dialog');
    await dialog.getByLabel('Название', { exact: true }).fill('Shopify Test');
    await dialog.getByLabel('Маркетплейс', { exact: true }).selectOption('shopify');
    await dialog.getByLabel('Домен магазина').fill('test.myshopify.com');
    await dialog.getByLabel('Client ID', { exact: true }).fill('client');
    await dialog.getByLabel('Client Secret', { exact: true }).fill('test-secret');
    await dialog.getByRole('button', { name: 'Проверить подключение', exact: true }).click();
    await expect(dialog.getByRole('status')).toContainText('API проверен');
    await dialog.getByRole('button', { name: 'Сохранить', exact: true }).click();
    await expect(page).toHaveURL(/\/marketplaces\/15$/);
    expect(f.mutations.find((m) => m.path.endsWith('/marketplace-accounts'))?.body).toMatchObject({
        marketplace: 'shopify',
        credentials: { client_id: 'client', client_secret: 'test-secret' },
    });
    await page.getByRole('tab', { name: 'Настройки', exact: true }).click();
    await expect(page.getByLabel('Частота синхронизации')).toHaveValue('manual');
    for (const value of ['hourly', 'daily', 'manual']) {
        await page.getByLabel('Частота синхронизации').selectOption(value);
        await page.getByRole('button', { name: 'Сохранить настройки' }).click();
        await expect(page.getByRole('status')).toContainText('сохранены');
        await expect.poll(() => f.account.integration.sync_frequency).toBe(value);
    }
    f.account.integration.initial_import_at = null as unknown as string;
    await page.getByRole('tab', { name: 'Обзор', exact: true }).click();
    await page.reload();
    await page.getByRole('button', { name: 'Выполнить первый импорт' }).click();
    await page.getByRole('dialog').getByRole('button', { name: 'Назад', exact: true }).click();
    expect(f.mutations.filter((m) => m.path.endsWith('/sync'))).toHaveLength(0);
    await page.getByRole('button', { name: 'Выполнить первый импорт' }).click();
    await page.getByRole('button', { name: 'Подтвердить импорт' }).click();
    await expect.poll(() => f.mutations.filter((m) => m.path.endsWith('/sync')).length).toBe(1);
    await page.getByRole('tab', { name: 'Товары', exact: true }).click();
    await expect(page.getByRole('link', { name: 'External Board', exact: true })).toBeVisible();
    await page.getByRole('tab', { name: 'Остатки', exact: true }).click();
    await expect(
        page.getByRole('table').getByRole('cell').filter({ hasText: 'Main' }),
    ).toBeVisible();
    await expect(page.getByLabel('Подключение', { exact: true })).toBeDisabled();
    await page.getByLabel('Внешняя локация', { exact: true }).selectOption('11');
    await page.getByRole('button', { name: 'Сбросить фильтры', exact: true }).click();
    await expect(page).toHaveURL(/tab=inventory/);
    await page.getByRole('tab', { name: 'История', exact: true }).click();
    await expect(page.getByRole('table').getByText('Завершён', { exact: true })).toBeVisible();
});

test('общие остатки различают локации, неизвестное наличие и информационную связь склада', async ({
    page,
}) => {
    const list = (data: unknown[]) => ({
        data,
        meta: { total: data.length, current_page: 1, last_page: 1, per_page: 20 },
        links: { first: '', last: '', prev: null, next: null },
    });
    const account = {
        id: 15,
        name: 'Shopify Inventory',
        marketplace: 'shopify',
        is_demo: false,
        status: 'active',
    };
    const base = {
        id: 103,
        marketplace_account_id: 15,
        marketplace_account: account,
        product: null,
        source_title: 'External Board',
        external_sku: 'EXT-SKU',
        price_minor: 100,
        currency: 'USD',
        status: 'active',
        inventory_origin: 'external_snapshot',
    };
    const rows = [
        {
            ...base,
            inventory_level_id: 1,
            marketplace_location_id: 11,
            location_name: 'Main',
            stock_quantity: 0,
            is_current: true,
            tracked: true,
        },
        {
            ...base,
            inventory_level_id: 2,
            marketplace_location_id: 12,
            location_name: 'Remote',
            stock_quantity: null,
            is_current: false,
            tracked: true,
        },
    ];
    const mapping: unknown[] = [];
    await page.route('**/api/v1/**', async (route) => {
        const url = new URL(route.request().url()),
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
                        preferences: { auto_start: false, checklist_collapsed: true },
                        progress: [],
                    },
                },
            });
        if (path.endsWith('/marketplace-accounts')) return route.fulfill({ json: list([account]) });
        if (path.endsWith('/warehouses'))
            return route.fulfill({ json: list([{ id: 8, name: 'Local Main' }]) });
        if (path.endsWith('/locations'))
            return route.fulfill({
                json: {
                    data: [
                        {
                            id: 11,
                            marketplace_account_id: 15,
                            name: 'Main',
                            is_active: true,
                            warehouse_id: null,
                        },
                        {
                            id: 12,
                            marketplace_account_id: 15,
                            name: 'Remote',
                            is_active: true,
                            warehouse_id: null,
                        },
                    ],
                },
            });
        if (path.endsWith('/mapping')) {
            mapping.push(route.request().postDataJSON().warehouse_id);
            return route.fulfill({ json: { data: {} } });
        }
        if (path.endsWith('/stocks')) {
            const loc = url.searchParams.get('location_id');
            return route.fulfill({
                json: list(
                    loc ? rows.filter((r) => String(r.marketplace_location_id) === loc) : rows,
                ),
            });
        }
        return route.fulfill({ json: { data: [] } });
    });
    await page.goto('/app/marketplace-stocks');
    await expect(page.getByText('External Board', { exact: true })).toHaveCount(2);
    await expect(page.getByRole('table').getByText('Неизвестно', { exact: true })).toBeVisible();
    await expect(page.getByText('Устаревший снимок', { exact: true })).toBeVisible();
    await expect(page.getByText('0 шт.', { exact: true })).toBeVisible();
    await page.getByLabel('Подключение', { exact: true }).selectOption('15');
    await page.getByLabel('Внешняя локация', { exact: true }).selectOption('11');
    await expect(page.getByText('External Board', { exact: true })).toHaveCount(1);
    await page.getByLabel('Сопоставление склада', { exact: true }).selectOption('8');
    await page.getByRole('button', { name: 'Сохранить связь', exact: true }).click();
    await expect.poll(() => mapping).toEqual([8]);
    await page.getByLabel('Сопоставление склада', { exact: true }).selectOption('');
    await page.getByRole('button', { name: 'Сохранить связь', exact: true }).click();
    await expect.poll(() => mapping).toEqual([8, null]);
});

test('старые ссылки Shopify ведут на общие страницы без отдельного каталога', async ({ page }) => {
    await fixture(page);
    await page.goto('/app/marketplaces/shopify/15?tab=products');
    await expect(page).toHaveURL(/\/app\/marketplaces\/15\?tab=products/);
    await expect(page.getByRole('link', { name: 'External Board', exact: true })).toBeVisible();
    await page.goto('/app/marketplaces/shopify/15/products/103');
    await expect(page).toHaveURL(/\/app\/catalog\/external_variant\/103/);
    await expect(page.getByRole('heading', { name: 'External Board', exact: true })).toBeVisible();
});

test('страница вариантов и её активный refresh сохраняются в URL после reload', async ({
    page,
}) => {
    const f = await fixture(page, 25);
    await page.goto('/app/catalog/external_product/17?sources_page=2');
    await expect(page.locator('.source-card')).toHaveCount(5);
    await page
        .locator('[data-variant="123"]')
        .getByRole('button', { name: 'Обновить из источника' })
        .click();
    await page.reload();
    await expect(page).toHaveURL(/sources_page=2/);
    await expect(
        page.locator('[data-variant="123"]').getByRole('button', { name: 'Обновляется…' }),
    ).toBeDisabled();
    await expect(
        page.locator('[data-variant="124"]').getByRole('button', { name: 'Обновить из источника' }),
    ).toBeEnabled();
    f.finish();
    await expect(
        page.getByRole('status').filter({ hasText: 'Вариант #123 обновлён' }),
    ).toBeVisible();
});
