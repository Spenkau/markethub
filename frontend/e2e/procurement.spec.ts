import { test, expect, type Page } from '@playwright/test';
import { spawnSync } from 'node:child_process';
import { randomBytes } from 'node:crypto';
import { fileURLToPath } from 'node:url';
const email = `procurement-${randomBytes(6).toString('hex')}@example.test`;
const password = randomBytes(24).toString('base64url');
const root = fileURLToPath(new URL('../../', import.meta.url));
function fixture(setup: boolean) {
    const script = String.raw`<?php
require 'vendor/autoload.php'; $app = require 'bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
if (config('database.default') !== 'pgsql' || !str_ends_with(config('database.connections.pgsql.database'), '_test')) { throw new RuntimeException('Isolated PostgreSQL test database required'); }
Illuminate\Support\Facades\Artisan::call('migrate:fresh', ['--force'=>true]);
if (getenv('PROCUREMENT_SETUP') === '1') {
    $org = App\Models\Organization::factory()->create();
    $user = App\Models\User::factory()->create(['name'=>'Purchasing Check','email'=>getenv('PROCUREMENT_EMAIL'),'password'=>getenv('PROCUREMENT_PASSWORD')]);
    $user->organizations()->attach($org);
    $warehouse = new App\Models\Warehouse(['name'=>'Основной','code'=>'MAIN','type'=>'own','is_active'=>true]); $warehouse->organization_id=$org->id; $warehouse->save();
    App\Models\Product::factory()->create(['organization_id'=>$org->id,'sku'=>'PROCUREMENT-1','name'=>'Товар поставки']);
    App\Models\Product::factory()->create(['organization_id'=>$org->id,'sku'=>'PROCUREMENT-2','name'=>'Второй товар']);
}`;
    const result = spawnSync('C:/tools/php83/php.exe', [], {
        cwd: root,
        input: script,
        encoding: 'utf8',
        env: {
            ...process.env,
            APP_ENV: 'testing',
            PROCUREMENT_SETUP: setup ? '1' : '0',
            PROCUREMENT_EMAIL: email,
            PROCUREMENT_PASSWORD: password,
        },
    });
    if (result.status !== 0) throw new Error(result.stderr || 'Fixture failed');
}
test.beforeAll(() => fixture(true));
test.afterAll(() => fixture(false));
async function supplier(page: Page, name: string) {
    await page.getByRole('button', { name: 'Новый поставщик', exact: true }).click();
    const dialog = page.getByRole('dialog');
    await dialog.getByLabel('Название поставщика', { exact: true }).fill(name);
    await dialog.getByLabel('Контактное лицо', { exact: true }).fill('Мария');
    await dialog.getByRole('button', { name: 'Сохранить поставщика' }).click();
    await expect(dialog).not.toBeVisible();
}
test('заказ поставщику, частичная и полная приёмка, повтор подтверждения и поставщики', async ({
    page,
}, testInfo) => {
    const errors: string[] = [];
    page.on('pageerror', (e) => errors.push(e.message));
    await page.goto('/app/purchases');
    await page.getByLabel('Email', { exact: true }).fill(email);
    await page.getByLabel('Пароль', { exact: true }).fill(password);
    await page.getByRole('button', { name: 'Войти в кабинет' }).click();
    await page.getByRole('tab', { name: 'Поставщики', exact: true }).click();
    await supplier(page, 'Тестовый поставщик');
    await expect(page.locator('tbody')).toContainText('Тестовый поставщик');
    await page.getByRole('tab', { name: 'Закупки', exact: true }).click();
    await page.getByRole('button', { name: 'Новая закупка', exact: true }).click();
    let dialog = page.getByRole('dialog');
    const vendor = dialog.getByLabel('Поставщик', { exact: true });
    await expect(vendor).toBeEnabled();
    await vendor.selectOption({ label: 'Тестовый поставщик · Поставщик' });
    const warehouse = dialog.getByLabel('Склад назначения', { exact: true });
    await expect(warehouse).toBeEnabled();
    await warehouse.selectOption({ label: 'Основной · MAIN' });
    const picker = dialog.getByLabel('Товар для закупки', { exact: true });
    await expect(picker).toBeEnabled();
    await picker.selectOption({ label: 'Товар поставки · PROCUREMENT-1' });
    await dialog.getByRole('button', { name: 'Добавить строку' }).click();
    await dialog.getByLabel('Количество: Товар поставки', { exact: true }).fill('10');
    await dialog.getByLabel('Закупочная цена: Товар поставки', { exact: true }).fill('0.10');
    await picker.selectOption({ label: 'Второй товар · PROCUREMENT-2' });
    await dialog.getByRole('button', { name: 'Добавить строку' }).click();
    await dialog.getByLabel('Количество: Второй товар', { exact: true }).fill('2');
    await dialog.getByLabel('Закупочная цена: Второй товар', { exact: true }).fill('0.20');
    await expect(dialog.locator('.purchase-total')).toContainText('1,40 RUB');
    await dialog.getByRole('button', { name: 'Сохранить черновик', exact: true }).click();
    await expect(page).toHaveURL(/\/app\/purchases\/\d+$/);
    const orderUrl = page.url();
    await page.getByRole('button', { name: 'Редактировать черновик' }).click();
    dialog = page.getByRole('dialog');
    await dialog.getByLabel('Закупочная цена: Товар поставки', { exact: true }).fill('0.20');
    await dialog.getByRole('button', { name: 'Сохранить черновик', exact: true }).click();
    await expect(dialog).not.toBeVisible();
    await expect(page.locator('.purchase-detail-summary')).toContainText('2,40 RUB');
    await page.getByRole('button', { name: 'Отметить заказанной' }).click();
    await expect(page.getByRole('button', { name: 'Создать приёмку', exact: true })).toBeVisible();
    await page.getByRole('button', { name: 'Создать приёмку', exact: true }).click();
    dialog = page.getByRole('dialog');
    await dialog.getByLabel('Принимаем: Товар поставки', { exact: true }).fill('4');
    await dialog.getByLabel('Принимаем: Второй товар', { exact: true }).fill('0');
    await dialog.getByRole('button', { name: 'Создать документ приёмки' }).click();
    await expect(page).toHaveURL(/\/purchases\/receipts\/\d+$/);
    const before = await page.request.get('/api/v1/warehouse-stocks');
    expect((await before.json()).meta.total).toBe(0);
    await page.route(
        '**/api/v1/goods-receipts/*/confirm',
        async (route) => {
            await route.fetch();
            await route.abort('failed');
        },
        { times: 1 },
    );
    await page.getByRole('button', { name: 'Подтвердить приёмку' }).click();
    await expect(page.getByRole('alert')).toContainText('Не удалось');
    const retry = page.waitForResponse((r) => r.url().endsWith('/confirm') && r.status() === 200);
    await page.getByRole('button', { name: 'Подтвердить приёмку' }).click();
    expect((await (await retry).json()).meta.replayed).toBe(true);
    await expect(page.getByRole('button', { name: 'Подтвердить приёмку' })).not.toBeVisible();
    await expect(page.locator('tbody')).toContainText('Движение #');
    await page.goto(orderUrl);
    await expect(page.locator('.page-heading')).toContainText('Частично получена');
    await page.getByRole('button', { name: 'Создать приёмку', exact: true }).click();
    dialog = page.getByRole('dialog');
    await expect(dialog.getByLabel('Принимаем: Товар поставки', { exact: true })).toHaveValue('6');
    await dialog.getByRole('button', { name: 'Создать документ приёмки' }).click();
    await expect(dialog).not.toBeVisible();
    await page.getByRole('button', { name: 'Подтвердить приёмку' }).click();
    await expect(page.getByRole('button', { name: 'Подтвердить приёмку' })).not.toBeVisible();
    await page.goto(orderUrl);
    await expect(page.locator('.page-heading')).toContainText('Получена');
    const stocks = await page.request.get('/api/v1/warehouse-stocks/summary');
    expect((await stocks.json()).data.units).toBe(12);
    const movements = await page.request.get('/api/v1/stock-movements');
    expect((await movements.json()).meta.total).toBe(3);
    await page.screenshot({ path: testInfo.outputPath('purchase-received.png'), fullPage: true });
    await page.goto('/app/purchases?tab=suppliers');
    await page.getByRole('button', { name: 'Удалить поставщика' }).click();
    await page
        .getByRole('alertdialog')
        .getByRole('button', { name: 'Удалить', exact: true })
        .click();
    await expect(page.getByRole('alertdialog').getByRole('alert')).toContainText('используется');
    await page
        .getByRole('alertdialog')
        .getByRole('button', { name: 'Отмена', exact: true })
        .click();
    await page.getByRole('button', { name: 'Редактировать поставщика' }).click();
    dialog = page.getByRole('dialog');
    await dialog.getByLabel('Поставщик активен').uncheck();
    await dialog.getByRole('button', { name: 'Сохранить поставщика' }).click();
    await expect(dialog).not.toBeVisible();
    await page.getByLabel('Активность поставщика').selectOption('0');
    await expect(page.locator('tbody tr')).toHaveCount(1);
    await supplier(page, 'Удаляемый');
    await page.getByLabel('Активность поставщика').selectOption('');
    const unused = page.locator('tbody tr').filter({ hasText: 'Удаляемый' });
    await unused.getByRole('button', { name: 'Удалить поставщика' }).click();
    await page
        .getByRole('alertdialog')
        .getByRole('button', { name: 'Удалить', exact: true })
        .click();
    await expect(page.getByRole('alertdialog')).not.toBeVisible();
    await page.goto('/app/purchases?tab=receipts');
    await expect(page.locator('tbody tr')).toHaveCount(2);
    await page.setViewportSize({ width: 390, height: 844 });
    await expect(page.getByLabel('Активная организация')).toBeVisible();
    await expect(page.locator('.sidebar')).toHaveCSS('transform', 'matrix(1, 0, 0, 1, -236, 0)');
    await page.screenshot({ path: testInfo.outputPath('receipts-mobile.png'), fullPage: true });
    expect(await page.evaluate(() => document.documentElement.scrollWidth)).toBeLessThanOrEqual(
        390,
    );
    expect(errors).toEqual([]);
});
