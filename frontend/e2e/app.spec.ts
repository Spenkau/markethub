import { test, expect } from '@playwright/test';
import { spawnSync } from 'node:child_process';
import { randomBytes } from 'node:crypto';
import { fileURLToPath } from 'node:url';
const email = `frontend-check-${randomBytes(8).toString('hex')}@example.test`;
const password = randomBytes(24).toString('base64url');
const sku = `UI-CHECK-${randomBytes(8).toString('hex')}`;
const root = fileURLToPath(new URL('../../', import.meta.url));
function php(body: string) {
    const result = spawnSync('C:/tools/php83/php.exe', [], {
        cwd: root,
        input: `<?php require 'vendor/autoload.php'; $app = require 'bootstrap/app.php'; $app->make(Illuminate\\Contracts\\Console\\Kernel::class)->bootstrap(); if (!app()->environment('testing') || config('database.default') !== 'pgsql' || !str_ends_with(config('database.connections.pgsql.database'), '_test')) { throw new RuntimeException('Isolated PostgreSQL test database required'); } ${body}`,
        env: {
            ...process.env,
            APP_ENV: 'testing',
            MARKETHUB_CHECK_EMAIL: email,
            MARKETHUB_CHECK_PASSWORD: password,
            MARKETHUB_CHECK_SKU: sku,
        },
        encoding: 'utf8',
    });
    if (result.status !== 0)
        throw new Error('Не удалось подготовить или очистить временную учётную запись.');
}
test.beforeAll(() =>
    php(
        `Illuminate\\Support\\Facades\\Artisan::call('migrate:fresh', ['--force'=>true]); Illuminate\\Support\\Facades\\Artisan::call('db:seed',['--class'=>'DemoDataSeeder','--force'=>true]); $org = App\\Models\\Organization::firstOrFail(); $user = App\\Models\\User::create(['tour_auto_start'=>false,'name'=>'Frontend Check','email'=>getenv('MARKETHUB_CHECK_EMAIL'),'password'=>Illuminate\\Support\\Facades\\Hash::make(getenv('MARKETHUB_CHECK_PASSWORD'))]); $user->organizations()->attach($org->id);`,
    ),
);
test.afterAll(() =>
    php(
        `App\\Models\\Product::withoutGlobalScopes()->where('sku',getenv('MARKETHUB_CHECK_SKU'))->delete(); App\\Models\\MarketplaceAccount::withoutGlobalScopes()->where('name',getenv('MARKETHUB_CHECK_SKU'))->delete(); App\\Models\\User::where('email',getenv('MARKETHUB_CHECK_EMAIL'))->delete();`,
    ),
);
test('Sanctum, восстановление сессии, каталог, CRUD, фильтры и мобильная навигация', async ({
    page,
}, testInfo) => {
    const runtimeErrors: string[] = [];
    page.on('pageerror', (error) => runtimeErrors.push(error.stack || error.message));
    await page.goto('/');
    await expect(page.getByRole('heading', { level: 1 })).toContainText('Управляйте продажами');
    await page.screenshot({ path: testInfo.outputPath('landing.png'), fullPage: true });
    await page.goto('/app/products');
    await expect(page).toHaveURL(/\/login/);
    await page.getByLabel('Email', { exact: true }).fill(email);
    await page.getByLabel('Пароль', { exact: true }).fill(password);
    await page.getByRole('button', { name: 'Войти в кабинет' }).click();
    await expect(page).toHaveURL(/\/app\/products(?:\?.*)?$/);
    await expect(page.locator('tbody tr').first()).toBeVisible();
    const withoutCsrf = await page.request.post('/api/v1/products', { data: {} });
    expect(withoutCsrf.status()).toBe(419);
    await page.reload();
    await expect(page.locator('tbody tr').first()).toBeVisible();
    await page.getByRole('link', { name: 'Добавить товар' }).click();
    await page.getByLabel('Название', { exact: true }).fill('Проверка интерфейса');
    await page.getByLabel('SKU').fill(sku);
    await page.getByLabel('Цена', { exact: true }).fill('1990,29');
    await page.getByRole('button', { name: 'Сохранить товар' }).click();
    await expect(page).toHaveURL(/\/app\/products(?:\?.*)?$/);
    await page.getByLabel('Поиск товаров').fill(sku);
    await expect(page).toHaveURL(/search=/);
    await expect(page.locator('tbody tr')).toHaveCount(1);
    await expect(page.locator('tbody')).toContainText('990,29 RUB');
    await page.getByRole('link', { name: 'Редактировать товар' }).click();
    await page.getByLabel('Название', { exact: true }).fill('Изменённый товар');
    await page.getByRole('button', { name: 'Сохранить товар' }).click();
    await expect(page).toHaveURL(/\/app\/products(?:\?.*)?$/);
    await page.getByLabel('Поиск товаров').fill(sku);
    await page.getByRole('link', { name: 'Изменённый товар', exact: true }).click();
    await expect(page.getByRole('heading', { level: 1 })).toHaveText('Изменённый товар');
    await page.goto('/app/products/new');
    await page.getByLabel('Название', { exact: true }).fill('Дубликат');
    await page.getByLabel('SKU').fill(sku);
    await page.getByLabel('Цена', { exact: true }).fill('1');
    const invalid = page.waitForResponse(
        (response) =>
            response.url().endsWith('/api/v1/products') && response.request().method() === 'POST',
    );
    await page.getByRole('button', { name: 'Сохранить товар' }).click();
    expect((await invalid).status()).toBe(422);
    await expect(page.locator('.field-error')).toBeVisible();
    await page.goto('/app/marketplaces');
    await expect(page.locator('.account-card').first()).toBeVisible();
    await page.getByRole('button', { name: 'Добавить подключение' }).click();
    await page.getByLabel('Название', { exact: true }).fill(sku);
    await page.getByRole('button', { name: 'Сохранить', exact: true }).click();
    await expect(page).toHaveURL(/\/marketplaces\/\d+$/);
    await expect(page.getByRole('heading', { name: sku, exact: true })).toBeVisible();
    await page.getByRole('button', { name: 'Изменить подключение', exact: true }).click();
    await page.getByRole('dialog').getByLabel('Статус').selectOption('disabled');
    await page.getByRole('button', { name: 'Сохранить', exact: true }).click();
    await expect(
        page.getByRole('status').filter({ hasText: 'Подключение сохранено' }),
    ).toBeVisible();
    await page.getByRole('link', { name: '← Все подключения' }).click();
    await page.getByLabel('Поиск подключений').fill(sku);
    await expect(page.locator('.account-card')).toHaveCount(1);
    await page.getByLabel('Фильтр статуса').selectOption('active');
    await expect(page.getByRole('heading', { name: 'Пока ничего нет' })).toBeVisible();
    await page.getByLabel('Фильтр статуса').selectOption('disabled');
    await expect(page.locator('.account-card')).toHaveCount(1);
    await page.locator('.account-card').getByRole('link', { name: 'Открыть размещения' }).click();
    await expect(page).toHaveURL(/tab=products/);
    await expect(page.getByRole('heading', { name: 'Каталог товаров', exact: true })).toBeVisible();
    await page.goto('/app/marketplace-stocks');
    await expect(page.locator('tbody tr').first()).toBeVisible();
    const sorted = page.waitForResponse(
        (r) => r.url().includes('/api/v1/stocks?') && r.url().includes('sort=price_minor'),
    );
    await page.getByLabel('Сортировка остатков').selectOption('price_minor');
    expect((await sorted).status()).toBe(200);
    await page.goto('/app/marketplace-stocks?stock=zero');
    await expect(page.getByLabel('Остаток', { exact: true })).toHaveValue('zero');
    await page.getByLabel('Остаток', { exact: true }).selectOption('');
    await expect(page.locator('tbody tr').first()).toBeVisible();
    await page.goto('/app');
    await expect(page.locator('.stat-card')).toHaveCount(4);
    await expect(page.locator('.stat-card').first().locator('strong')).not.toHaveText('');
    await expect(page.getByRole('heading', { name: 'Остатки на площадках' })).toBeVisible();
    await expect(page.getByRole('heading', { name: 'Недавно добавлены' })).toBeVisible();
    const total = await page.request.get('/api/v1/products?per_page=1');
    const totalBody = await total.json();
    await expect(page.locator('.stat-card').first().locator('strong')).toHaveText(
        totalBody.meta.total.toLocaleString('ru-RU'),
    );
    await page.screenshot({ path: testInfo.outputPath('dashboard.png'), fullPage: true });
    await page.route('**/api/v1/products?**', (route) =>
        route.fulfill({ status: 503, contentType: 'application/json', body: '{}' }),
    );
    await page.getByRole('button', { name: 'Обновить обзор' }).click();
    await expect(page.getByRole('heading', { name: 'Не удалось загрузить данные' })).toBeVisible();
    await page.unroute('**/api/v1/products?**');
    await page.getByRole('button', { name: 'Повторить', exact: true }).click();
    await expect(page.locator('.stat-card')).toHaveCount(4);
    await page.setViewportSize({ width: 390, height: 844 });
    await page.getByRole('button', { name: 'Открыть меню' }).click();
    await expect(page.locator('.sidebar')).toHaveClass(/opened/);
    await page.locator('nav').getByRole('link', { name: 'Товары', exact: true }).click();
    await page.getByLabel('Поиск товаров').fill(sku);
    await expect(page.locator('tbody tr')).toHaveCount(1);
    await page.screenshot({ path: testInfo.outputPath('products-mobile.png'), fullPage: true });
    const overflow = await page.evaluate(() =>
        Array.from(document.querySelectorAll('*'))
            .filter(
                (el) =>
                    el.getBoundingClientRect().right > 391 &&
                    getComputedStyle(el).overflowX === 'visible',
            )
            .map((el) => ({
                tag: el.tagName,
                class: el.className,
                right: el.getBoundingClientRect().right,
            }))
            .slice(0, 15),
    );
    expect(
        await page.evaluate(() => document.documentElement.scrollWidth),
        JSON.stringify(overflow),
    ).toBeLessThanOrEqual(390);
    await page.getByRole('button', { name: 'Удалить товар' }).click();
    await page.getByRole('button', { name: 'Удалить', exact: true }).click();
    await expect(page.getByRole('heading', { name: 'Пока ничего нет' })).toBeVisible();
    await page.getByRole('button', { name: 'Выйти', exact: true }).click();
    await expect(page).toHaveURL(/\/login/);
    await page.goto('/app');
    await expect(page).toHaveURL(/\/login/);
    expect(runtimeErrors).toEqual([]);
});
