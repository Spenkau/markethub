import { test, expect, type Page } from '@playwright/test';
import { spawnSync } from 'node:child_process';
import { randomBytes } from 'node:crypto';
import { fileURLToPath } from 'node:url';
const email = `dashboard-${randomBytes(6).toString('hex')}@example.test`;
const password = randomBytes(24).toString('base64url');
const root = fileURLToPath(new URL('../../', import.meta.url));
function database(setup: boolean) {
    const code = String.raw`<?php
require 'vendor/autoload.php';
$app = require 'bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
if (!app()->environment('testing') || config('database.default') !== 'pgsql' || !str_ends_with(config('database.connections.pgsql.database'), '_test')) throw new RuntimeException('Isolated PostgreSQL test database required');
Illuminate\Support\Facades\Artisan::call('migrate:fresh', ['--force'=>true]);
if (getenv('DASHBOARD_SETUP') !== '1') exit;
$org = App\Models\Organization::factory()->create(['name'=>'Dashboard business']);
$empty = App\Models\Organization::factory()->create(['name'=>'Empty business']);
$user = App\Models\User::factory()->create(['tour_auto_start'=>false,'active_organization_id'=>$org->id,'email'=>getenv('DASHBOARD_EMAIL'),'password'=>getenv('DASHBOARD_PASSWORD')]);
$user->organizations()->attach([$org->id,$empty->id]);
$p = App\Models\Product::factory()->create(['organization_id'=>$org->id,'name'=>'Local dashboard SKU','sku'=>'DASH-SKU']);
$a = App\Models\MarketplaceAccount::factory()->create(['organization_id'=>$org->id,'marketplace'=>'shopify','name'=>'Dashboard Shopify','is_demo'=>false,'status'=>'active']);
$a->forceFill(['api_status'=>'connected','shop_domain'=>'dashboard-test.myshopify.com','sync_frequency'=>'manual','initial_import_at'=>now(),'last_synced_at'=>now()])->save();
$run = App\Models\MarketplaceSyncRun::create(['id'=>(string)Illuminate\Support\Str::uuid(),'organization_id'=>$org->id,'marketplace_account_id'=>$a->id,'provider'=>'shopify','scope'=>'account','status'=>'running','trigger'=>'manual','sync_products'=>true,'sync_inventory'=>true,'reconcile_token'=>(string)Illuminate\Support\Str::uuid()]);
$store = app(App\Services\Marketplaces\MarketplaceInventoryService::class);
$store->location($a,$run,new App\Services\Marketplaces\ExternalLocation('A','Main location',true,now()->toIso8601String()));
foreach (['1','2'] as $id) {
 $listing = app(App\Services\Marketplaces\MarketplaceImportService::class)->upsert($a,['external_id'=>'gid://shopify/Product/99','title'=>'Dashboard parent','status'=>'active','images'=>[],'source_data'=>[]],['external_id'=>'gid://shopify/ProductVariant/'.$id,'external_sku'=>'DASH-EXT-'.$id,'variant_title'=>'Variant '.$id,'price_minor'=>100,'currency'=>'USD','status'=>'active','source_synced_at'=>now()->toIso8601String()],[])['id'];
 if($id==='1') Illuminate\Support\Facades\DB::table('marketplace_listings')->where('id',$listing)->update(['product_id'=>$p->id]);
 $store->snapshots($a,$run,$listing,[new App\Services\Marketplaces\ExternalInventorySnapshot('A',true,3,[],null,now()->toIso8601String())]);
 if($id==='2') Illuminate\Support\Facades\DB::table('marketplace_inventory_levels')->where('marketplace_listing_id',$listing)->update(['is_current'=>false,'stale_reason'=>'api_error']);
}
$run->update(['status'=>'completed','finished_at'=>now()]);
foreach(['wildberries','ozon'] as $provider) {
 $demo=App\Models\MarketplaceAccount::factory()->create(['organization_id'=>$org->id,'marketplace'=>$provider,'name'=>'Demo '.$provider,'is_demo'=>true,'status'=>'demo']);
 App\Models\MarketplaceListing::factory()->create(['organization_id'=>$org->id,'marketplace_account_id'=>$demo->id,'product_id'=>$p->id,'stock_quantity'=>4]);
}
echo 'DASHBOARD_FIXTURE_READY';
`;
    const result = spawnSync(process.env.PHP_BINARY || 'php', [], {
        cwd: root,
        input: code,
        encoding: 'utf8',
        env: {
            ...process.env,
            APP_ENV: 'testing',
            DASHBOARD_SETUP: setup ? '1' : '0',
            DASHBOARD_EMAIL: email,
            DASHBOARD_PASSWORD: password,
        },
    });
    if (result.status !== 0 || (setup && !result.stdout.includes('DASHBOARD_FIXTURE_READY')))
        throw new Error(result.stderr || result.stdout || 'Dashboard fixture failed');
}
test.beforeAll(() => database(true));
test.afterAll(() => database(false));
async function login(page: Page) {
    await page.goto('/login');
    await page.getByLabel('Email', { exact: true }).fill(email);
    await page.getByLabel('Пароль', { exact: true }).fill(password);
    await page.getByRole('button', { name: 'Войти в кабинет' }).click();
    await expect(page.locator('.dashboard-kpis')).toBeVisible();
}
test('Достоверные KPI, явный demo, URL-фильтр и рабочие переходы', async ({ page }, info) => {
    await login(page);
    const overview = await (await page.request.get('/api/v1/dashboard/overview')).json();
    expect(overview.data.products).toMatchObject({
        local_skus: 1,
        external_products: 1,
        external_variants: 2,
    });
    await expect(
        page.locator('.dashboard-kpi').filter({ hasText: 'Локальные SKU' }).locator('strong'),
    ).toHaveText('1');
    await expect(
        page.locator('.dashboard-kpi').filter({ hasText: 'Внешние товары' }).locator('strong'),
    ).toHaveText('1');
    await expect(
        page.locator('.dashboard-kpi').filter({ hasText: 'Внешние варианты' }).locator('strong'),
    ).toHaveText('2');
    await expect(page.locator('.dashboard-connection')).toHaveCount(1);
    await page.getByLabel('Включить демонстрационные данные').check();
    await expect(page.locator('.dashboard-connection')).toHaveCount(3);
    await expect(
        page.locator('.dashboard-connection').filter({ hasText: 'Демонстрационные данные' }),
    ).toHaveCount(2);
    await page.getByLabel('Область обзора', { exact: true }).selectOption('shopify');
    await expect(page.locator('.dashboard-connection')).toHaveCount(1);
    await expect(page).toHaveURL(/provider=shopify/);
    await page.screenshot({ path: info.outputPath('dashboard-desktop.png'), fullPage: true });
    await page
        .locator('.attention-item')
        .filter({ hasText: 'Внешние варианты не сопоставлены' })
        .click();
    await expect(page).toHaveURL(/mapping=unmapped/);
    await expect(page.getByLabel('Сопоставление', { exact: true })).toHaveValue('unmapped');
    await expect(page.locator('tbody')).toContainText('Dashboard parent');
    await page.goto('/app?provider=shopify');
    await expect(page.locator('.dashboard-kpis')).toBeVisible();
    await page.locator('.attention-item').filter({ hasText: 'Устаревшие внешние снимки' }).click();
    await expect(page.getByLabel('Актуальность', { exact: true })).toHaveValue('0');
    await expect(page.locator('tbody tr')).toHaveCount(1);
    await page.goto('/app');
    await expect(page.locator('.dashboard-kpis')).toBeVisible();
    await page.getByRole('link', { name: 'Заказаны, ожидают приёмки', exact: true }).click();
    await expect(page.getByLabel('Статус документа')).toHaveValue('ordered');
});
test('Экскурсия, точечный запуск, mobile и смена организации', async ({ page }, info) => {
    await login(page);
    await page.getByRole('button', { name: 'Как это работает?', exact: true }).click();
    await expect(page.getByRole('button', { name: 'Далее', exact: true })).toBeVisible();
    await page.getByRole('button', { name: 'Далее', exact: true }).click();
    await page.getByRole('button', { name: 'Назад', exact: true }).click();
    await page.getByRole('button', { name: 'Пропустить', exact: true }).click();
    await expect(page.getByRole('button', { name: 'Далее', exact: true })).not.toBeVisible();
    const response = page.waitForResponse(
        (r) => r.url().endsWith('/sync') && r.request().method() === 'POST',
    );
    await page.getByRole('button', { name: 'Синхронизировать сейчас', exact: true }).click();
    expect((await response).status()).toBe(202);
    await expect(
        page.getByRole('status').filter({ hasText: 'поставлена в очередь' }),
    ).toBeVisible();
    await page.setViewportSize({ width: 390, height: 844 });
    await page.screenshot({ path: info.outputPath('dashboard-mobile.png'), fullPage: true });
    expect(
        await page.evaluate(() => document.documentElement.scrollWidth <= window.innerWidth),
    ).toBe(true);
    const accountOption = await page
        .getByLabel('Область обзора', { exact: true })
        .locator('option')
        .filter({ hasText: 'Dashboard Shopify' })
        .getAttribute('value');
    await page.getByLabel('Область обзора', { exact: true }).selectOption(accountOption!);
    await expect(page).toHaveURL(/marketplace_account_id=/);
    await expect(page.locator('.dashboard-kpis')).toBeVisible();
    await page.getByLabel('Активная организация').selectOption({ label: 'Empty business' });
    await expect(
        page.getByRole('heading', { name: 'Начните с первого полезного действия' }),
    ).toBeVisible();
    await expect(
        page.locator('.dashboard-kpi').filter({ hasText: 'Локальные SKU' }).locator('strong'),
    ).toHaveText('0');
    await expect(page.locator('.dashboard-connection')).toHaveCount(0);
    await expect(page).not.toHaveURL(/marketplace_account_id=/);
    await expect(page.getByRole('heading', { name: 'Размещения по площадкам' })).not.toBeVisible();
    await page.screenshot({ path: info.outputPath('dashboard-empty-mobile.png'), fullPage: true });
});
