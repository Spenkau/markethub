<?php

namespace Tests\Feature;

use App\Models\MarketplaceAccount;
use App\Models\MarketplaceListing;
use App\Models\MarketplaceSyncRun;
use App\Models\Organization;
use App\Models\Product;
use App\Models\Supplier;
use App\Models\User;
use App\Models\Warehouse;
use App\Models\WarehouseStock;
use App\Services\Marketplaces\ExternalInventorySnapshot;
use App\Services\Marketplaces\ExternalLocation;
use App\Services\Marketplaces\MarketplaceImportService;
use App\Services\Marketplaces\MarketplaceInventoryService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Tests\TestCase;

class DashboardTest extends TestCase
{
    use RefreshDatabase;

    private Organization $org;

    private MarketplaceAccount $account;

    private Product $product;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();
        Http::preventStrayRequests();
        $this->org = Organization::factory()->create();
        $this->product = Product::factory()->create(['organization_id' => $this->org->id]);
        $this->account = MarketplaceAccount::factory()->create(['organization_id' => $this->org->id, 'marketplace' => 'shopify', 'is_demo' => false, 'status' => 'active']);
        $this->account->forceFill(['api_status' => 'connected', 'sync_frequency' => 'manual'])->save();
        $this->user = User::factory()->create(['active_organization_id' => $this->org->id]);
        $this->user->organizations()->attach($this->org);
        $this->actingAs($this->user);
    }

    private function source(string $id, ?int $local = null): int
    {
        $id = app(MarketplaceImportService::class)->upsert($this->account,
            ['external_id' => 'gid://shopify/Product/99', 'title' => 'Parent', 'status' => 'active', 'images' => [], 'source_data' => []],
            ['external_id' => 'gid://shopify/ProductVariant/'.$id, 'external_sku' => 'EXT-'.$id, 'variant_title' => 'Variant '.$id, 'price_minor' => 100, 'currency' => 'USD', 'status' => 'active', 'source_synced_at' => now()->toIso8601String()], ['secret' => 'DASHBOARD-RAW-SECRET'])['id'];
        DB::table('marketplace_listings')->where('id', $id)->update(['product_id' => $local]);

        return $id;
    }

    private function syncRun(string $status, array $extra = []): MarketplaceSyncRun
    {
        return MarketplaceSyncRun::create(['id' => (string) Str::uuid(), 'organization_id' => $this->org->id, 'marketplace_account_id' => $this->account->id, 'provider' => 'shopify', 'scope' => 'account', 'status' => $status, 'trigger' => 'manual', 'reconcile_token' => (string) Str::uuid(), 'sync_products' => true, 'sync_inventory' => true, ...$extra]);
    }

    private function level(MarketplaceSyncRun $run, int $listing, string $location, ?int $quantity, bool $tracked = true): void
    {
        $store = app(MarketplaceInventoryService::class);
        $store->location($this->account, $run, new ExternalLocation($location, $location, true, now()->toIso8601String()));
        $store->snapshots($this->account, $run, $listing, [new ExternalInventorySnapshot($location, $tracked, $quantity, [], null, now()->toIso8601String())]);
    }

    public function test_units_are_not_duplicated_by_providers_variants_or_locations(): void
    {
        $a = $this->source('1', $this->product->id);
        $b = $this->source('2');
        foreach (['wildberries', 'ozon'] as $provider) {
            $demo = MarketplaceAccount::factory()->create(['organization_id' => $this->org->id, 'marketplace' => $provider, 'is_demo' => true, 'status' => 'demo']);
            MarketplaceListing::factory()->create(['organization_id' => $this->org->id, 'marketplace_account_id' => $demo->id, 'product_id' => $this->product->id]);
        }
        $run = $this->syncRun('running');
        $this->level($run, $a, 'A', 2);
        $this->level($run, $a, 'B', 2);
        $this->level($run, $b, 'A', 0);
        $this->getJson('/api/v1/dashboard/overview')->assertOk()
            ->assertJsonPath('data.products.local_skus', 1)->assertJsonPath('data.products.external_products', 1)
            ->assertJsonPath('data.products.external_variants', 2)->assertJsonPath('data.products.unmapped_variants', 1)
            ->assertJsonPath('data.products.represented_local_skus', 1)->assertJsonPath('data.external_inventory.low_variants', 1)
            ->assertJsonPath('data.external_inventory.zero_variants', 1)->assertJsonPath('data.connections.excluded_demo', 2);
        $this->getJson('/api/v1/dashboard/overview?include_demo=1')->assertOk()->assertJsonPath('data.products.local_skus', 1)
            ->assertJsonPath('data.products.represented_local_skus', 1)->assertJsonPath('data.products.external_variants', 4)->assertJsonPath('data.connections.demo', 2);
    }

    public function test_unknown_zero_stale_negative_and_untracked_are_distinct(): void
    {
        $run = $this->syncRun('running');
        foreach ([['zero', 0, true], ['unknown', null, true], ['negative', -3, true], ['untracked', 5, false], ['stale', 2, true]] as [$id, $qty, $tracked]) {
            $listing = $this->source($id);
            $this->level($run, $listing, 'A', $qty, $tracked);
            if ($id === 'stale') {
                DB::table('marketplace_inventory_levels')->where('marketplace_listing_id', $listing)->update(['is_current' => false, 'stale_reason' => 'api_error']);
            }
        }
        $this->source('no-level');
        $r = $this->getJson('/api/v1/dashboard/overview')->assertOk()
            ->assertJsonPath('data.external_inventory.zero_variants', 1)->assertJsonPath('data.external_inventory.low_variants', 0)
            ->assertJsonPath('data.external_inventory.negative_variants', 1)->assertJsonPath('data.external_inventory.untracked_variants', 1)
            ->assertJsonPath('data.external_inventory.unknown_variants', 4)->assertJsonPath('data.external_inventory.no_snapshot_variants', 1)->assertJsonPath('data.external_inventory.stale_snapshots', 1)
            ->assertJsonPath('data.physical.zero_skus', 0)->assertJsonPath('data.physical.no_data_skus', 1);
        $stale = collect($r->json('data.attention'))->firstWhere('id', 'stale');
        $this->assertStringContainsString('is_current=0', $stale['url']);
    }

    public function test_physical_totals_sum_per_sku_and_are_not_filtered_by_marketplace(): void
    {
        $w1 = Warehouse::create(['organization_id' => $this->org->id, 'name' => 'Warehouse', 'code' => (string) Str::uuid(), 'type' => 'own', 'is_active' => true]);
        $w2 = Warehouse::create(['organization_id' => $this->org->id, 'name' => 'Warehouse', 'code' => (string) Str::uuid(), 'type' => 'own', 'is_active' => true]);
        $zero = Product::factory()->create(['organization_id' => $this->org->id]);
        foreach ([[$this->product->id, $w1->id, 3], [$this->product->id, $w2->id, 3], [$zero->id, $w1->id, 0]] as [$p,$w,$q]) {
            WarehouseStock::create(['organization_id' => $this->org->id, 'warehouse_id' => $w, 'product_id' => $p, 'quantity' => $q]);
        }
        $a = $this->getJson('/api/v1/dashboard/overview')->assertOk()->assertJsonPath('data.physical.low_skus', 0)->assertJsonPath('data.physical.zero_skus', 1)->assertJsonPath('data.physical.units', 6)->json('data.physical');
        $b = $this->getJson('/api/v1/dashboard/overview?provider=ozon')->assertOk()->json('data.physical');
        $this->assertSame($a, $b);
    }

    public function test_latest_failed_and_partial_runs_override_older_success_but_manual_is_not_overdue(): void
    {
        $this->syncRun('completed', ['created_at' => now()->subHours(2)]);
        $latest = $this->syncRun('failed');
        $r = $this->getJson('/api/v1/dashboard/overview')->assertOk()->assertJsonPath('data.connections.problems', 1)->assertJsonPath('data.connections.overdue_syncs', 0)->assertJsonPath('data.connection_rows.0.latest_run.status', 'failed');
        $this->assertSame($latest->id, $r->json('data.activity.0.id'));
        $latest->update(['status' => 'partial']);
        $this->getJson('/api/v1/dashboard/overview')->assertOk()->assertJsonPath('data.connections.problems', 1);
    }

    public function test_account_and_provider_filters_and_attention_deep_links(): void
    {
        $this->source('1');
        $other = MarketplaceAccount::factory()->create(['organization_id' => $this->org->id, 'marketplace' => 'shopify', 'is_demo' => false, 'status' => 'active']);
        $r = $this->getJson('/api/v1/dashboard/overview?provider=shopify&marketplace_account_id='.$this->account->id)->assertOk()->assertJsonPath('data.products.external_variants', 1);
        $url = collect($r->json('data.attention'))->firstWhere('id', 'unmapped')['url'];
        parse_str(parse_url($url, PHP_URL_QUERY), $params);
        $this->assertSame('shopify', $params['marketplace']);
        $this->assertSame('unmapped', $params['mapping']);
        $this->getJson('/api/v1/catalog?'.http_build_query($params))->assertOk()->assertJsonPath('meta.total', 1);
        $this->getJson('/api/v1/dashboard/overview?marketplace_account_id='.$other->id)->assertOk()->assertJsonPath('data.products.external_variants', 0);
        $this->getJson('/api/v1/dashboard/overview?provider=ozon&marketplace_account_id='.$this->account->id)->assertNotFound();
    }

    public function test_tenant_isolation_organization_switch_and_validation(): void
    {
        $this->source('1');
        $other = Organization::factory()->create();
        $this->user->organizations()->attach($other);
        $this->patchJson('/api/v1/organizations/active', ['organization_id' => $other->id])->assertSuccessful();
        $this->getJson('/api/v1/dashboard/overview')->assertOk()->assertJsonPath('data.products.local_skus', 0)->assertJsonPath('data.products.external_variants', 0)->assertJsonPath('data.activity', [])->assertJsonPath('data.availability.empty_organization', true);
        $this->getJson('/api/v1/dashboard/overview?marketplace_account_id='.$this->account->id)->assertNotFound();
        $this->getJson('/api/v1/dashboard/overview?organization_id='.$this->org->id)->assertUnprocessable();
        $this->getJson('/api/v1/dashboard/overview?provider=invalid')->assertUnprocessable();
        $this->getJson('/api/v1/dashboard/overview?include_demo=invalid')->assertUnprocessable();
    }

    public function test_read_does_not_expose_secrets_or_call_external_api_and_queries_are_bounded(): void
    {
        $this->source('1');
        DB::enableQueryLog();
        DB::flushQueryLog();
        $r = $this->getJson('/api/v1/dashboard/overview')->assertOk();
        $first = count(DB::getQueryLog());
        DB::disableQueryLog();
        foreach (range(1, 15) as $i) {
            MarketplaceAccount::factory()->create(['organization_id' => $this->org->id, 'marketplace' => 'shopify', 'is_demo' => false, 'status' => 'active']);
        }
        DB::enableQueryLog();
        DB::flushQueryLog();
        $this->getJson('/api/v1/dashboard/overview')->assertOk();
        $second = count(DB::getQueryLog());
        DB::disableQueryLog();
        $this->assertLessThanOrEqual($first + 1, $second);
        $this->assertLessThan(40, $second);
        foreach (['source_data', 'DASHBOARD-RAW-SECRET', 'client_secret', 'credentials', 'reconcile_token'] as $secret) {
            $this->assertStringNotContainsString($secret, $r->getContent());
        }
        Http::assertNothingSent();
    }

    public function test_authentication_and_organization_are_required(): void
    {
        auth()->forgetGuards();
        $this->getJson('/api/v1/dashboard/overview')->assertUnauthorized();
        $this->actingAs(User::factory()->create());
        $this->getJson('/api/v1/dashboard/overview')->assertForbidden();
    }

    public function test_procurement_and_activity_use_actual_confirmations_and_statuses(): void
    {
        $warehouse = Warehouse::create(['organization_id' => $this->org->id, 'name' => 'Main', 'code' => 'MAIN', 'type' => 'own', 'is_active' => true]);
        $supplier = Supplier::create(['organization_id' => $this->org->id, 'name' => 'Supplier']);
        $order = $this->postJson('/api/v1/purchase-orders', ['request_id' => (string) Str::uuid(), 'supplier_id' => $supplier->id, 'warehouse_id' => $warehouse->id, 'currency' => 'RUB', 'items' => [['product_id' => $this->product->id, 'quantity' => 10, 'unit_price' => '1.00']]])->assertCreated()->json('data');
        $this->postJson('/api/v1/purchase-orders/'.$order['id'].'/order')->assertOk();
        $receipt = $this->postJson('/api/v1/goods-receipts', ['request_id' => (string) Str::uuid(), 'purchase_order_id' => $order['id'], 'items' => [['purchase_order_item_id' => $order['items'][0]['id'], 'quantity' => 2]]])->assertCreated()->json('data');
        $before = $this->getJson('/api/v1/dashboard/overview')->assertOk()->assertJsonPath('data.procurement.open_orders', 1)->assertJsonPath('data.procurement.awaiting_receipt', 1)->assertJsonPath('data.procurement.draft_receipts', 1);
        $this->assertCount(0, $before->json('data.activity'));
        $this->postJson('/api/v1/goods-receipts/'.$receipt['id'].'/confirm')->assertOk();
        $after = $this->getJson('/api/v1/dashboard/overview?provider=ozon')->assertOk()->assertJsonPath('data.procurement.awaiting_receipt', 0)->assertJsonPath('data.procurement.partially_received', 1)->assertJsonPath('data.procurement.draft_receipts', 0)->assertJsonPath('data.physical.units', 2);
        $this->assertEqualsCanonicalizing(['stock', 'receipt'], array_column($after->json('data.activity'), 'kind'));
        foreach ($after->json('data.activity') as $event) {
            $this->assertStringStartsWith('/app/', $event['url']);
        }
    }

    public function test_demo_inventory_and_stale_links_apply_the_same_filters_in_destination_api(): void
    {
        $listing = $this->source('1');
        $run = $this->syncRun('running');
        $this->level($run, $listing, 'A', 2);
        DB::table('marketplace_inventory_levels')->where('marketplace_listing_id', $listing)->update(['is_current' => false, 'stale_reason' => 'api_error']);
        $demo = MarketplaceAccount::factory()->create(['organization_id' => $this->org->id, 'marketplace' => 'ozon', 'is_demo' => true, 'status' => 'demo']);
        MarketplaceListing::factory()->create(['organization_id' => $this->org->id, 'marketplace_account_id' => $demo->id, 'product_id' => $this->product->id]);
        $r = $this->getJson('/api/v1/dashboard/overview?provider=shopify')->assertOk();
        $link = collect($r->json('data.attention'))->firstWhere('id', 'stale')['url'];
        $this->getJson('/api/v1/marketplace-inventory?'.parse_url($link, PHP_URL_QUERY))->assertOk()->assertJsonPath('meta.total', 1);
        $this->getJson('/api/v1/marketplace-inventory?is_demo=0')->assertOk()->assertJsonPath('meta.total', 1);
        $this->getJson('/api/v1/marketplace-inventory?is_demo=1')->assertOk()->assertJsonPath('meta.total', 1)->assertJsonPath('data.0.marketplace_account.is_demo', true);
    }
}
