<?php

namespace App\Services;

use App\Models\MarketplaceAccount;
use App\Models\MarketplaceInventoryLevel;
use App\Models\MarketplaceListing;
use App\Models\MarketplaceLocation;
use App\Models\MarketplaceSyncRun;
use App\Models\Organization;
use App\Models\Product;
use App\Models\Supplier;
use App\Models\User;
use App\Models\Warehouse;
use App\Services\Marketplaces\MarketplaceImportService;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/** Called only after the dedicated database + empty database checks, inside one transaction. */
class SyntheticDemoFixtures
{
    public function create(string $email, string $password): void
    {
        $previous = Auth::user();
        $user = User::create(['name' => 'Demo', 'email' => $email, 'password' => $password]);
        $org = Organization::create(['name' => 'Demo', 'slug' => PublicDemo::ORGANIZATION]);
        $user->organizations()->attach($org);
        $user->forceFill(['active_organization_id' => $org->id])->save();
        Auth::setUser($user);
        try {
            $products = [];
            foreach (['Настольная лампа', 'Наушники', 'Термобутылка', 'Органайзер', 'Рюкзак'] as $i => $name) {
                $products[] = Product::create(['sku' => 'SYNTHETIC-'.($i + 1), 'name' => $name.' · demo', 'description' => 'Синтетический пример MarketHub. Не является предложением о продаже.', 'base_price_minor' => 3500 + $i * 1700, 'currency' => 'BYN', 'is_active' => true]);
            }
            $warehouse = Warehouse::create(['name' => 'Demo · основной склад', 'code' => 'DEMO-MAIN', 'type' => 'own', 'is_active' => true]);
            $reserve = Warehouse::create(['name' => 'Demo · резерв', 'code' => 'DEMO-RESERVE', 'type' => 'own', 'is_active' => true]);
            $supplier = Supplier::create(['name' => 'Синтетический поставщик', 'email' => 'supplier@example.invalid', 'comment' => 'Вымышленная организация, только demo.', 'is_active' => true]);
            $procurement = app(ProcurementService::class);
            foreach ([[$products[0], 20, 8], [$products[1], 10, 10]] as [$product, $quantity, $received]) {
                $order = $procurement->createOrder(['request_id' => (string) Str::uuid(), 'supplier_id' => $supplier->id, 'warehouse_id' => $warehouse->id, 'currency' => 'BYN', 'comment' => 'Синтетическая закупка demo', 'items' => [['product_id' => $product->id, 'quantity' => $quantity, 'unit_price' => '25.00']]], $user)['record'];
                $order = $procurement->transition($order, 'order', $user);
                $receipt = $procurement->createReceipt(['request_id' => (string) Str::uuid(), 'purchase_order_id' => $order->id, 'comment' => 'Синтетическая приёмка demo', 'items' => [['purchase_order_item_id' => $order->items->first()->id, 'quantity' => $received]]], $user)['record'];
                $procurement->confirm($receipt, $user);
            }
            $stocks = app(StockOperationService::class);
            $stocks->execute(['operation_id' => (string) Str::uuid(), 'type' => 'receipt', 'warehouse_id' => $warehouse->id, 'product_id' => $products[2]->id, 'quantity' => 2, 'comment' => 'Demo: начальная поставка'], $user);
            $stocks->execute(['operation_id' => (string) Str::uuid(), 'type' => 'write_off', 'warehouse_id' => $warehouse->id, 'product_id' => $products[2]->id, 'quantity' => 2, 'comment' => 'Demo: подтверждённый нулевой остаток'], $user);
            $stocks->execute(['operation_id' => (string) Str::uuid(), 'type' => 'transfer', 'warehouse_id' => $warehouse->id, 'destination_warehouse_id' => $reserve->id, 'product_id' => $products[0]->id, 'quantity' => 3, 'comment' => 'Demo: перемещение'], $user);
            foreach (['wildberries', 'ozon'] as $code) {
                $account = MarketplaceAccount::create(['marketplace' => $code, 'name' => strtoupper($code).' · синтетическое demo', 'status' => 'demo', 'is_demo' => true]);
                foreach (array_slice($products, 0, 3) as $i => $product) {
                    MarketplaceListing::create(['product_id' => $product->id, 'marketplace_account_id' => $account->id, 'external_id' => 'SYNTHETIC-'.$code.'-'.$i, 'external_sku' => 'DEMO-'.$i, 'price_minor' => 4500 + $i * 1000, 'currency' => 'BYN', 'stock_quantity' => $i === 2 ? 0 : 12 + $i, 'status' => 'active']);
                }
            }
            $account = MarketplaceAccount::create(['marketplace' => 'shopify_demo', 'name' => 'Shopify-like · синтетические товары (без API)', 'status' => 'demo', 'is_demo' => true]);
            $run = MarketplaceSyncRun::create(['id' => (string) Str::uuid(), 'organization_id' => $org->id, 'marketplace_account_id' => $account->id, 'provider' => 'shopify_demo', 'dispatch_state' => 'published', 'trigger' => 'initial', 'status' => 'completed', 'sync_products' => true, 'sync_inventory' => true, 'started_at' => now()->subMinutes(2), 'finished_at' => now()->subMinute(), 'details' => ['synthetic_demo' => true, 'note' => 'Fixture history only; no API requests.']]);
            $locations = [];
            foreach (['Вымышленный внешний склад A', 'Вымышленный внешний склад B'] as $i => $name) {
                $locations[] = MarketplaceLocation::create(['organization_id' => $org->id, 'marketplace_account_id' => $account->id, 'external_id' => 'SYNTHETIC-LOCATION-'.$i, 'name' => $name, 'is_active' => true, 'source_synced_at' => now(), 'seen_run' => $run->id, 'metadata' => ['synthetic_demo' => true]]);
            }
            foreach (['Лампа', 'Наушники'] as $p => $title) {
                foreach (['Светлый', 'Тёмный'] as $v => $variant) {
                    $source = app(MarketplaceImportService::class)->upsert($account,
                        ['external_id' => 'SYNTHETIC-PARENT-'.$p, 'title' => $title.' · synthetic demo', 'description' => 'Вымышленный Shopify-like товар. Не импортирован из Shopify API.', 'status' => 'active', 'source_data' => ['synthetic_demo' => true], 'images' => [['url' => '/demo-assets/product-'.($p + 1).'.svg', 'altText' => $title.' · demo'], ['url' => '/demo-assets/product-3.svg', 'altText' => 'Дополнительный demo ракурс']], 'source_synced_at' => now()->toIso8601String()],
                        ['external_id' => 'SYNTHETIC-VARIANT-'.$p.'-'.$v, 'external_sku' => 'DEMO-V-'.$p.'-'.$v, 'variant_title' => $variant, 'price_minor' => 4900 + $p * 500, 'currency' => 'BYN', 'status' => 'active', 'source_status' => 'ACTIVE', 'source_synced_at' => now()->toIso8601String()], ['synthetic_demo' => true]);
                    if ($v === 0) {
                        DB::table('marketplace_listings')->where('id', $source['id'])->update(['product_id' => $products[$p]->id]);
                    }
                    foreach ($locations as $i => $location) {
                        $available = $p === 1 && $v === 1 ? null : ($v === 1 ? 0 : 7 + $i);
                        MarketplaceInventoryLevel::create(['organization_id' => $org->id, 'marketplace_account_id' => $account->id, 'marketplace_listing_id' => $source['id'], 'marketplace_location_id' => $location->id, 'tracked' => $available !== null, 'available' => $available, 'source_synced_at' => now(), 'seen_run' => $run->id, 'is_current' => true, 'metadata' => ['synthetic_demo' => true], 'quantities' => ['available' => $available]]);
                    }
                }
            }
            $user->forceFill(['is_demo_access' => true])->save();
        } finally {
            if ($previous) {
                Auth::setUser($previous);
            } else {
                Auth::forgetUser();
            }
        }
    }
}
