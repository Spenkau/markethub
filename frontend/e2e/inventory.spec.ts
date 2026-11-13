import { test, expect, type Page } from '@playwright/test';
import { spawnSync } from 'node:child_process';
import { randomBytes } from 'node:crypto';
import { fileURLToPath } from 'node:url';
const email = `inventory-${randomBytes(6).toString('hex')}@example.test`;
const password = randomBytes(24).toString('base64url');
const root = fileURLToPath(new URL('../../', import.meta.url));
function database(setup: boolean) {
    const code = String.raw`<?php
require 'vendor/autoload.php';
$app = require 'bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
if (config('database.default') !== 'pgsql' || !str_ends_with(config('database.connections.pgsql.database'), '_test')) { throw new RuntimeException('Isolated PostgreSQL test database required'); }
Illuminate\Support\Facades\Artisan::call('migrate:fresh', ['--force' => true]);
if (getenv('INVENTORY_E2E_SETUP') === '1') {
    $org = App\Models\Organization::factory()->create();
    $user = App\Models\User::factory()->create(['name'=>'Inventory Check', 'email'=>getenv('INVENTORY_E2E_EMAIL'), 'password'=>getenv('INVENTORY_E2E_PASSWORD')]);
    $user->organizations()->attach($org);
    $product = App\Models\Product::factory()->create(['organization_id'=>$org->id,'sku'=>'WAREHOUSE-CHECK','name'=>'Складской товар']);
    $account = App\Models\MarketplaceAccount::factory()->create(['organization_id'=>$org->id]);
    App\Models\MarketplaceListing::factory()->create(['organization_id'=>$org->id,'product_id'=>$product->id,'marketplace_account_id'=>$account->id,'stock_quantity'=>77]);
}
`;
    const result = spawnSync(process.env.PHP_BINARY || 'php', [], {
        cwd: root,
        input: code,
        encoding: 'utf8',
        env: {
            ...process.env,
            APP_ENV: 'testing',
            INVENTORY_E2E_SETUP: setup ? '1' : '0',
            INVENTORY_E2E_EMAIL: email,
            INVENTORY_E2E_PASSWORD: password,
        },
    });
    if (result.status !== 0) throw new Error(result.stderr || 'Inventory fixture setup failed');
}
test.beforeAll(() => database(true));
test.afterAll(() => database(false));

async function createWarehouse(page: Page, name: string, code: string) {
    await page.getByRole('button', { name: 'Новый склад', exact: true }).click();
    const dialog = page.getByRole('dialog');
    await dialog.getByLabel('Название склада', { exact: true }).fill(name);
    await dialog.getByLabel('Код склада', { exact: true }).fill(code);
    await dialog.getByRole('button', { name: 'Сохранить склад' }).click();
    await expect(dialog).not.toBeVisible();
}
async function openOperation(page: Page, label: string, quantity: number, destination = false) {
    await page.getByRole('button', { name: label, exact: true }).click();
    const dialog = page.getByRole('dialog');
    const product = dialog.getByLabel('Товар', { exact: true });
    await expect(product).toBeEnabled();
    await product.selectOption({ label: 'Складской товар · WAREHOUSE-CHECK' });
    const source = dialog.getByLabel(destination ? 'Исходный склад' : 'Склад', { exact: true });
    await expect(source).toBeEnabled();
    await source.selectOption({ label: 'Основной склад · MAIN' });
    if (destination) {
        const target = dialog.getByLabel('Целевой склад', { exact: true });
        await expect(target).toBeEnabled();
        await target.selectOption({ label: 'Резерв · RESERVE' });
    }
    await dialog.getByRole('spinbutton').fill(String(quantity));
    return dialog;
}
test('складской цикл, повтор после обрыва ответа, история и отдельные остатки площадки', async ({
    page,
}, testInfo) => {
    const runtimeErrors: string[] = [];
    page.on('pageerror', (e) => runtimeErrors.push(e.message));
    await page.goto('/app/stocks');
    await page.getByLabel('Email', { exact: true }).fill(email);
    await page.getByLabel('Пароль', { exact: true }).fill(password);
    await page.getByRole('button', { name: 'Войти в кабинет' }).click();
    await expect(
        page.getByRole('heading', { name: 'Склады и остатки', exact: true }),
    ).toBeVisible();
    await createWarehouse(page, 'Основной склад', 'MAIN');
    await createWarehouse(page, 'Резерв', 'RESERVE');
    await page.getByRole('tab', { name: 'Склады', exact: true }).click();
    await expect(page.locator('.warehouse-card')).toHaveCount(2);
    let dialog = await openOperation(page, 'Поступление', 10);
    await page.route(
        '**/api/v1/stock-operations',
        async (route) => {
            await route.fetch();
            await route.abort('failed');
        },
        { times: 1 },
    );
    await dialog.getByRole('button', { name: 'Провести операцию' }).click();
    await expect(dialog.getByRole('alert')).toContainText('Не удалось');
    const replay = page.waitForResponse(
        (r) => r.url().endsWith('/stock-operations') && r.status() === 200,
    );
    await dialog.getByRole('button', { name: 'Провести операцию' }).click();
    expect((await (await replay).json()).meta.replayed).toBe(true);
    await expect(dialog).not.toBeVisible();
    dialog = await openOperation(page, 'Списание', 11);
    await dialog.getByRole('button', { name: 'Провести операцию' }).click();
    await expect(dialog.locator('.field-error')).toContainText('Недостаточно');
    await dialog.getByRole('button', { name: 'Отмена', exact: true }).click();
    for (const [label, qty, transfer] of [
        ['Списание', 2, false],
        ['Перемещение', 3, true],
    ] as const) {
        dialog = await openOperation(page, label, qty, transfer);
        await dialog.getByRole('button', { name: 'Провести операцию' }).click();
        await expect(dialog).not.toBeVisible();
    }
    dialog = await openOperation(page, 'Инвентаризация', 1);
    await dialog.getByLabel('Склад', { exact: true }).selectOption({ label: 'Резерв · RESERVE' });
    await dialog.getByLabel('Комментарий', { exact: true }).fill('Пересчёт');
    await dialog.getByRole('button', { name: 'Провести операцию' }).click();
    await expect(dialog).not.toBeVisible();
    await page.getByRole('tab', { name: 'Остатки', exact: true }).click();
    await expect(page.locator('tbody tr')).toHaveCount(2);
    await expect(page.locator('tbody')).toContainText('5 шт.');
    await expect(page.locator('tbody')).toContainText('1 шт.');
    await page.getByRole('tab', { name: 'История движений' }).click();
    await expect(page.locator('tbody tr')).toHaveCount(5);
    await page.getByLabel('Тип движения').selectOption('transfer');
    await expect(page.locator('tbody tr')).toHaveCount(2);
    await expect(page.locator('tbody')).toContainText('-3');
    await expect(page.locator('tbody')).toContainText('+3');
    await page.screenshot({ path: testInfo.outputPath('movements.png'), fullPage: true });
    const platform = await page.request.get('/api/v1/stocks');
    expect((await platform.json()).data[0].stock_quantity).toBe(77);
    await page.goto('/app');
    await expect(page.getByTestId('dashboard-physical-units').locator('dd')).toHaveText('6');
    await page.goto('/app/marketplace-stocks');
    await expect(page.locator('tbody')).toContainText('77 шт.');
    await page.goto('/app/stocks');
    await page.setViewportSize({ width: 390, height: 844 });
    await expect(page.locator('tbody tr')).toHaveCount(2);
    await page.screenshot({ path: testInfo.outputPath('warehouses-mobile.png'), fullPage: true });
    expect(await page.evaluate(() => document.documentElement.scrollWidth)).toBeLessThanOrEqual(
        390,
    );
    expect(runtimeErrors).toEqual([]);
});
