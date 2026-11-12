import { test, expect, type Page } from '@playwright/test';
import { spawnSync } from 'node:child_process';
import { fileURLToPath } from 'node:url';
import { randomBytes } from 'node:crypto';
const root = fileURLToPath(new URL('../../', import.meta.url));
const email = `onboarding-${randomBytes(6).toString('hex')}@example.test`;
const password = randomBytes(24).toString('base64url');
function fixture(automatic: boolean, setup = true, skipWelcome = false) {
    const code = String.raw`<?php
require 'vendor/autoload.php'; $app = require 'bootstrap/app.php'; $app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
if (config('database.default') !== 'pgsql' || !str_ends_with(config('database.connections.pgsql.database'), '_test')) throw new RuntimeException('Isolated PostgreSQL test database required');
Illuminate\Support\Facades\Artisan::call('migrate:fresh', ['--force'=>true]);
if (getenv('ONBOARDING_SETUP') === '1') $created = App\Models\User::factory()->create(['name'=>'Onboarding Check','email'=>getenv('ONBOARDING_EMAIL'),'password'=>getenv('ONBOARDING_PASSWORD'),'tour_auto_start'=>getenv('ONBOARDING_AUTO')==='1']);
if (isset($created) && getenv('ONBOARDING_SKIP_WELCOME') === '1') App\Models\UserTourProgress::create(['user_id'=>$created->id,'tour_id'=>'welcome','version'=>1,'status'=>'skipped','step_id'=>'welcome','step_index'=>0]);
`;
    const result = spawnSync('C:/tools/php83/php.exe', [], {
        cwd: root,
        input: code,
        encoding: 'utf8',
        env: {
            ...process.env,
            APP_ENV: 'testing',
            ONBOARDING_SETUP: setup ? '1' : '0',
            ONBOARDING_AUTO: automatic ? '1' : '0',
            ONBOARDING_SKIP_WELCOME: skipWelcome ? '1' : '0',
            ONBOARDING_EMAIL: email,
            ONBOARDING_PASSWORD: password,
        },
    });
    if (result.status !== 0) throw new Error(result.stderr || 'Fixture failed');
}
async function login(page: Page, path = '/app') {
    await page.goto(path);
    await page.getByLabel('Email', { exact: true }).fill(email);
    await page.getByLabel('Пароль', { exact: true }).fill(password);
    await page.getByRole('button', { name: 'Войти в кабинет' }).click();
    await expect(page).not.toHaveURL(/\/login/);
}
async function next(page: Page) {
    const response = page.waitForResponse(
        (r) =>
            r.url().includes('/onboarding/tours/') &&
            r.request().method() === 'PATCH' &&
            r.request().postDataJSON()?.action === 'advance',
    );
    const button = page
        .locator('.tour-tooltip')
        .getByRole('button', { name: 'Далее', exact: true });
    await expect(button).toBeEnabled();
    await button.click();
    expect((await response).status()).toBe(200);
    await expect(page.locator('.tour-tooltip .tour-buttons .primary')).toBeEnabled();
}
async function finish(page: Page) {
    const response = page.waitForResponse(
        (r) =>
            r.url().includes('/onboarding/tours/') &&
            r.request().method() === 'PATCH' &&
            r.request().postDataJSON()?.action === 'complete',
    );
    const button = page
        .locator('.tour-tooltip')
        .getByRole('button', { name: 'Завершить', exact: true });
    await expect(button).toBeEnabled();
    await button.click();
    expect((await response).status()).toBe(200);
    await expect(page.locator('.tour-tooltip')).not.toBeVisible();
}
test.afterAll(() => fixture(false, false));

test('сквозной путь с нулевой организации, реальные этапы, контекст закупки и документ движения', async ({
    page,
}) => {
    fixture(false);
    await login(page);
    await expect(page.locator('[data-tour="getting-started"]')).toContainText('0 из 6');
    await page.getByRole('button', { name: 'Создать организацию', exact: true }).click();
    let dialog = page.getByRole('dialog');
    await dialog.getByLabel('Название организации').fill('Моя организация');
    await dialog.getByRole('button', { name: 'Создать организацию', exact: true }).click();
    await expect(page.locator('[data-tour="getting-started"]')).toContainText('1 из 6');
    await page.getByTestId('checklist-warehouse').getByRole('link').click();
    await page.getByRole('button', { name: 'Новый склад', exact: true }).click();
    dialog = page.getByRole('dialog');
    await dialog.getByLabel('Название склада', { exact: true }).fill('Основной');
    await dialog.getByLabel('Код склада', { exact: true }).fill('MAIN');
    await dialog.getByRole('button', { name: 'Сохранить склад' }).click();
    await expect(dialog).not.toBeVisible();
    await page.locator('.next-action-banner').getByRole('link', { name: 'Добавить товар' }).click();
    await page.getByLabel('Название', { exact: true }).fill('Путь товара');
    await page.getByLabel('SKU').fill('JOURNEY-1');
    await page.getByLabel('Цена', { exact: true }).fill('10');
    await page.getByRole('button', { name: 'Сохранить товар' }).click();
    await expect(page.locator('.next-action-banner')).toContainText('Путь товара');
    const product = await (await page.request.get('/api/v1/products')).json();
    const productId = product.data[0].id;
    await page
        .locator('.next-action-banner')
        .getByRole('link', { name: 'К складским остаткам' })
        .click();
    await expect(page).toHaveURL(/product_id=/);
    await page.goto('/app/purchases?tab=suppliers');
    await page.getByRole('button', { name: 'Новый поставщик', exact: true }).click();
    dialog = page.getByRole('dialog');
    await dialog.getByLabel('Название поставщика').fill('Наш поставщик');
    await dialog.getByRole('button', { name: 'Сохранить поставщика' }).click();
    await expect(dialog).not.toBeVisible();
    await page
        .locator('.next-action-banner')
        .getByRole('link', { name: 'Создать закупку у поставщика' })
        .click();
    dialog = page.getByRole('dialog');
    await expect(dialog.getByLabel('Поставщик', { exact: true })).not.toHaveValue('');
    await dialog
        .getByLabel('Склад назначения', { exact: true })
        .selectOption({ label: 'Основной · MAIN' });
    await dialog
        .getByLabel('Товар для закупки', { exact: true })
        .selectOption({ label: 'Путь товара · JOURNEY-1' });
    await dialog.getByRole('button', { name: 'Добавить строку' }).click();
    await dialog.getByLabel('Количество: Путь товара', { exact: true }).fill('3');
    await dialog.getByLabel('Закупочная цена: Путь товара', { exact: true }).fill('2.00');
    await dialog.getByRole('button', { name: 'Сохранить черновик', exact: true }).click();
    await expect(page).toHaveURL(/\/app\/purchases\/\d+$/);
    await page.getByRole('button', { name: 'Отметить заказанной' }).click();
    await page.getByRole('button', { name: 'Создать приёмку', exact: true }).click();
    dialog = page.getByRole('dialog');
    await dialog.getByRole('button', { name: 'Создать документ приёмки' }).click();
    await expect(dialog).not.toBeVisible();
    await page.getByRole('button', { name: 'Подтвердить приёмку' }).click();
    await page
        .locator('.next-action-banner')
        .getByRole('link', { name: 'К остаткам склада' })
        .click();
    await expect(page).toHaveURL(/warehouse_id=/);
    await expect(page.locator('tbody')).toContainText('3 шт.');
    const stockUrl = page.url();
    await page
        .locator('tbody')
        .getByRole('link', { name: 'Создать закупку', exact: false })
        .click();
    dialog = page.getByRole('dialog');
    await expect(dialog.locator('.purchase-edit-line')).toContainText('Путь товара');
    await expect(dialog.getByLabel('Склад назначения', { exact: true })).not.toHaveValue('');
    await dialog.getByRole('button', { name: 'Отмена', exact: true }).click();
    await page.goto(stockUrl);
    await page.locator('tbody').getByRole('link', { name: 'История', exact: false }).click();
    await page
        .locator('tbody')
        .getByRole('link', { name: /Приёмка #/ })
        .click();
    await expect(page).toHaveURL(/\/purchases\/receipts\/\d+$/);
    await page.goto(`/app/products/${productId}`);
    await page.getByRole('link', { name: 'Создать закупку с товаром' }).click();
    dialog = page.getByRole('dialog');
    await expect(dialog.locator('.purchase-edit-line')).toContainText('Путь товара');
    await dialog.getByRole('button', { name: 'Отмена', exact: true }).click();
    await page.goto('/app');
    await expect(page.locator('[data-tour="getting-started"]')).toContainText('6 из 6');
    await page.getByRole('button', { name: 'Свернуть или развернуть начало работы' }).click();
    await expect(page.getByTestId('checklist-warehouse')).not.toBeVisible();
    await page.reload();
    await expect(page.getByTestId('checklist-warehouse')).not.toBeVisible();
});

test('первое предложение, переходы экскурсии, reload, продолжение и другое устройство', async ({
    page,
    browser,
}) => {
    fixture(true);
    await login(page);
    await expect(page.locator('.tour-offer')).toBeVisible();
    await page.getByRole('button', { name: 'Начать экскурсию' }).click();
    await expect(page.locator('.tour-tooltip')).toContainText('Шаг 1 из 5');
    await next(page);
    await expect(page.locator('.tour-highlight')).toBeVisible();
    await next(page);
    await next(page);
    await next(page);
    await expect(page.locator('.tour-tooltip')).toContainText('Шаг 5 из 5');
    await finish(page);
    await page.goto('/app/help');
    await page
        .getByTestId('tour-products')
        .getByRole('button', { name: 'Начать', exact: true })
        .click();
    await next(page);
    await page.reload();
    await expect(page.locator('.tour-tooltip')).not.toBeVisible();
    await page.goto('/app/help');
    await page
        .getByTestId('tour-products')
        .getByRole('button', { name: 'Продолжить', exact: true })
        .click();
    await expect(page.locator('.tour-tooltip')).toContainText('Шаг 2 из 6');
    await next(page);
    await expect(page).toHaveURL(/products\/new/);
    await expect(page.locator('.tour-highlight')).toBeVisible();
    await page.keyboard.press('Escape');
    await expect(page.locator('.tour-tooltip')).not.toBeVisible();
    const other = await browser.newContext();
    const second = await other.newPage();
    await login(second, '/app/help');
    await expect(second.getByTestId('tour-products')).toContainText('Пропущена');
    await other.close();
    await page.goto('/app/help');
    await page.getByTestId('tour-products').getByRole('button', { name: 'Пройти заново' }).click();
    await expect(page.locator('.tour-tooltip')).toContainText('Шаг 1 из 6');
    await next(page);
    await next(page);
    await next(page);
    await next(page);
    await next(page);
    await finish(page);
    await page.goto('/app');
    await expect(page.locator('[data-tour="getting-started"]')).toContainText('0 из 6');
});

test('отложенное обучение, настройка, новые конфигурации и версии, мобильный экран', async ({
    page,
}, info) => {
    fixture(true);
    await login(page);
    await expect(page.locator('.tour-offer')).toBeVisible();
    await page.getByRole('button', { name: 'Напомнить позже', exact: true }).click();
    await expect(page.locator('.tour-offer')).not.toBeVisible();
    await page.reload();
    await expect(page.locator('.tour-offer')).not.toBeVisible();
    await page.goto('/app/help');
    await expect(page.getByTestId('tour-welcome')).toContainText('Отложена');
    await page.getByLabel('Автоматически предлагать подсказки').uncheck();
    await expect
        .poll(
            async () =>
                (await (await page.request.get('/api/v1/onboarding')).json()).data.preferences
                    .auto_start,
        )
        .toBe(false);
    await expect(
        page.getByRole('heading', { name: 'Помощь и обучение', exact: true }),
    ).toBeVisible();
    // goto reloads the registry, so inject the test scenario and launch in the same SPA session.
    await page.evaluate(async () => {
        const registry = await import('/src/features/onboarding/config/tours.ts');
        const engine = await import('/src/features/onboarding/composables/useProductTour.ts');
        registry.tours.push({
            id: 'config-test',
            version: 1,
            title: 'Тест конфигурации',
            description: '',
            steps: [
                {
                    id: 'missing',
                    route: '/app/products',
                    target: '[data-tour="missing-target"]',
                    title: 'Нет элемента',
                    description: 'Безопасный пропуск подсветки.',
                },
                {
                    id: 'done',
                    route: '/app/help',
                    title: 'Готово',
                    description: 'Конфигурация работает.',
                },
            ],
        });
        await engine.useProductTour().load(true);
    });
    await page
        .getByTestId('tour-config-test')
        .getByRole('button', { name: 'Начать', exact: true })
        .click();
    await expect(page.locator('.tour-tooltip')).toContainText('Элемент сейчас недоступен');
    await next(page);
    await finish(page);
    await expect(page.getByTestId('tour-config-test')).toContainText('Завершена');
    await page.evaluate(async () => {
        const registry = await import('/src/features/onboarding/config/tours.ts');
        registry.tours.find((t) => t.id === 'config-test')!.version = 2;
        const engine = await import('/src/features/onboarding/composables/useProductTour.ts');
        await engine.useProductTour().load(true);
    });
    await page
        .getByTestId('tour-config-test')
        .getByRole('button', { name: 'Начать', exact: true })
        .click();
    await expect(page.locator('.tour-tooltip')).toContainText('Шаг 1 из 2');
    await next(page);
    await finish(page);
    const data = await (await page.request.get('/api/v1/onboarding')).json();
    expect(data.data.preferences.auto_start).toBe(false);
    expect(
        data.data.progress.filter((p: { tour_id: string }) => p.tour_id === 'config-test'),
    ).toHaveLength(2);
    await page.setViewportSize({ width: 390, height: 844 });
    await page
        .getByTestId('tour-marketplaces')
        .getByRole('button', { name: 'Начать', exact: true })
        .click();
    await expect(page.locator('.tour-tooltip')).toBeVisible();
    const box = await page.locator('.tour-tooltip').boundingBox();
    expect(box!.x).toBeGreaterThanOrEqual(0);
    expect(box!.x + box!.width).toBeLessThanOrEqual(390);
    await page.keyboard.press('Tab');
    expect(
        await page.locator('.tour-tooltip').evaluate((el) => el.contains(document.activeElement)),
    ).toBe(true);
    await page.screenshot({ path: info.outputPath('tour-mobile.png'), fullPage: true });
    await page.keyboard.press('Escape');
});

test('первое посещение раздела, защита форм и декларативное раскрытие без бизнес-записей', async ({
    page,
}) => {
    fixture(true, true, true);
    await login(page, '/app/purchases?tab=suppliers');
    const writes: string[] = [];
    page.on('request', (request) => {
        if (
            ['POST', 'PATCH', 'DELETE'].includes(request.method()) &&
            /\/api\/v1\/(suppliers|purchase-orders|goods-receipts|warehouses|products|stock-operations)/.test(
                request.url(),
            )
        )
            writes.push(request.url());
    });
    await page.getByRole('button', { name: 'Новый поставщик', exact: true }).click();
    await page.waitForTimeout(1200); // Observe past the configured 800ms automatic-offer delay while a form is open.
    await expect(page.locator('.tour-offer')).not.toBeVisible();
    await page.getByRole('dialog').getByRole('button', { name: 'Отмена', exact: true }).click();
    await expect(page.locator('.tour-offer')).toContainText('Поставщики');
    await page.getByRole('button', { name: 'Начать экскурсию' }).click();
    await next(page);
    await next(page);
    await expect(page.locator('[data-tour="supplier-fields"]')).toBeVisible();
    await next(page);
    await expect(page.locator('[data-tour="supplier-fields"]')).not.toBeVisible();
    await finish(page);
    await page.goto('/app/help');
    await page
        .getByTestId('tour-purchases')
        .getByRole('button', { name: 'Начать', exact: true })
        .click();
    await next(page);
    await next(page);
    await expect(page.locator('[data-tour="purchase-parties"]')).toBeVisible();
    await next(page);
    await expect(page.locator('[data-tour="purchase-items"]')).toBeVisible();
    await next(page);
    await expect(page.locator('[data-tour="purchase-parties"]')).not.toBeVisible();
    await next(page);
    await finish(page);
    expect(writes).toEqual([]);
});

test('обновлённая экскурсия Shopify использует общие маршруты и существующие anchors', async ({
    page,
}) => {
    fixture(false);
    await login(page, '/app/help');
    await page
        .getByTestId('tour-shopify')
        .getByRole('button', { name: 'Начать', exact: true })
        .click();
    await expect(page.locator('.tour-highlight')).toBeVisible();
    await expect(page.locator('[data-tour="create-marketplace"]')).toBeVisible();
    await next(page);
    const back = page.locator('.tour-tooltip').getByRole('button', { name: 'Назад', exact: true });
    await back.click();
    await expect(page.locator('.tour-tooltip')).toContainText('Шаг 1 из 7');
    await expect(page.locator('.tour-highlight')).toBeVisible();
    await next(page);
    for (let i = 2; i < 7; i++) {
        await next(page);
        await expect(page.locator('.tour-highlight')).toBeVisible();
        await expect(
            page.getByText('Элемент сейчас недоступен', { exact: false }),
        ).not.toBeVisible();
    }
    await finish(page);
    await page.goto('/app/help');
    await expect(page.getByTestId('tour-shopify')).toContainText('Завершена');
});
