<?php

namespace Tests\Feature;

use App\Models\MarketplaceAccount;
use App\Models\MarketplaceListing;
use App\Models\MarketplaceSyncRun;
use App\Models\Organization;
use App\Models\Product;
use App\Models\User;
use App\Models\Warehouse;
use App\Services\Marketplaces\CatalogMappingService;
use App\Services\Marketplaces\ExternalInventorySnapshot;
use App\Services\Marketplaces\ExternalLocation;
use App\Services\Marketplaces\MarketplaceImportService;
use App\Services\Marketplaces\MarketplaceInventoryService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Tests\TestCase;

class UnifiedCatalogTest extends TestCase
{
    use RefreshDatabase;

    private Organization $org;

    private MarketplaceAccount $account;

    private Product $local;

    private int $first;

    private int $second;

    protected function setUp(): void
    {
        parent::setUp();
        Queue::fake();
        $this->org = Organization::factory()->create();
        $this->account = MarketplaceAccount::factory()->create(['organization_id' => $this->org->id, 'marketplace' => 'shopify', 'is_demo' => false, 'status' => 'active']);
        $this->account->forceFill(['shop_domain' => 'catalog-test.myshopify.com'])->save();
        $this->local = Product::factory()->create(['organization_id' => $this->org->id, 'name' => 'Local Board', 'sku' => 'LOCAL-SKU']);
        $this->first = $this->source('1');
        $this->second = $this->source('2');
        $u = User::factory()->create(['active_organization_id' => $this->org->id]);
        $u->organizations()->attach($this->org);
        $this->actingAs($u);
    }

    private function source(string $id, ?MarketplaceAccount $account = null): int
    {
        return app(MarketplaceImportService::class)->upsert($account ?? $this->account, ['external_id' => 'gid://shopify/Product/99', 'title' => 'External Snowboard', 'description' => '<p>Description</p>', 'status' => 'active', 'images' => [['url' => 'https://example.test/photo.png', 'altText' => 'Board']], 'source_data' => []], ['external_id' => 'gid://shopify/ProductVariant/'.$id, 'external_sku' => 'EXT-'.$id, 'barcode' => 'BAR-'.$id, 'variant_title' => 'Size '.$id, 'price_minor' => 900, 'currency' => 'USD', 'status' => 'active', 'source_synced_at' => now()->toIso8601String()], ['secret' => 'NEVER-SERIALIZE'])['id'];
    }

    public function test_local_skus_and_grouped_unmapped_parent_are_shown_without_creating_products(): void
    {
        $r = $this->getJson('/api/v1/catalog')->assertOk()->assertJsonPath('meta.total', 2);
        $external = collect($r->json('data'))->firstWhere('kind', 'external_product');
        $this->assertSame(2, $external['sources_count']);
        $this->assertCount(2, $external['sources']);
        $this->assertSame('unmapped', $external['mapping']);
        $this->assertDatabaseCount('products', 1);
        $this->assertStringNotContainsString('NEVER-SERIALIZE', $r->getContent());
        $this->assertStringNotContainsString('source_data', $r->getContent());
        $this->getJson('/api/v1/products')->assertOk()->assertJsonPath('meta.total', 1);
    }

    public function test_multiple_placements_do_not_duplicate_local_product(): void
    {
        $mapping = app(CatalogMappingService::class);
        $mapping->map($this->account, $this->first, $this->local->id);
        $mapping->map($this->account, $this->second, $this->local->id);
        $r = $this->getJson('/api/v1/catalog')->assertOk()->assertJsonPath('meta.total', 1)->assertJsonPath('data.0.kind', 'local')->assertJsonPath('data.0.sources_count', 2);
        $this->assertCount(2, $r->json('data.0.sources'));
        $this->getJson('/api/v1/catalog?mapping=unmapped')->assertOk()->assertJsonPath('meta.total', 0);
        $this->getJson('/api/v1/catalog?mapping=mapped')->assertOk()->assertJsonPath('meta.total', 1);
    }

    public function test_provider_account_search_and_server_pagination(): void
    {
        foreach (['External Snowboard', 'EXT-1', 'BAR-2', 'gid://shopify/ProductVariant/1', 'gid://shopify/Product/99'] as $search) {
            $this->getJson('/api/v1/catalog?search='.urlencode($search))->assertOk()->assertJsonPath('meta.total', 1);
        }
        $this->getJson('/api/v1/catalog?marketplace=shopify&marketplace_account_id='.$this->account->id)->assertOk()->assertJsonPath('meta.total', 1);
        $this->getJson('/api/v1/catalog?marketplace=ozon')->assertOk()->assertJsonPath('meta.total', 0);
        Product::factory()->count(25)->create(['organization_id' => $this->org->id]);
        $a = $this->getJson('/api/v1/catalog?per_page=10&page=1')->assertOk()->assertJsonPath('meta.total', 27)->json('data');
        $b = $this->getJson('/api/v1/catalog?per_page=10&page=2')->assertOk()->json('data');
        $this->assertEmpty(array_intersect(array_column($a, 'key'), array_column($b, 'key')));
    }

    public function test_details_show_images_variants_admin_links_and_current_run(): void
    {
        $parent = DB::table('marketplace_listings')->where('id', $this->first)->value('marketplace_product_id');
        $run = MarketplaceSyncRun::create(['id' => (string) Str::uuid(), 'organization_id' => $this->org->id, 'marketplace_account_id' => $this->account->id, 'provider' => 'shopify', 'status' => 'queued', 'scope' => 'variant', 'target_listing_id' => $this->first, 'trigger' => 'manual', 'sync_products' => true, 'sync_inventory' => true]);
        $r = $this->getJson('/api/v1/catalog/external_product/'.$parent)->assertOk()->assertJsonPath('data.sources_count', 2)->assertJsonPath('data.images.0.url', 'https://example.test/photo.png')->assertJsonPath('data.sources.0.admin_url', 'https://catalog-test.myshopify.com/admin/products/99')->assertJsonPath('data.sources.0.active_run.id', $run->id)->assertJsonPath('data.sources.1.active_run', null);
        $this->assertNull($r->json('data.local_product'));
        $this->getJson('/api/v1/catalog/external_variant/'.$this->first)->assertOk()->assertJsonPath('data.sources_count', 1)->assertJsonPath('data.sources.0.id', $this->first);
        $this->getJson('/api/v1/catalog/local/'.$this->local->id)->assertOk()->assertJsonPath('data.local_product.id', $this->local->id);
    }

    public function test_mapping_creating_and_unmapping_use_existing_service_and_update_projection(): void
    {
        $this->patchJson('/api/v1/marketplace-listings/'.$this->first.'/mapping', ['product_id' => $this->local->id])->assertNoContent();
        $this->getJson('/api/v1/catalog?search=EXT-1')->assertOk()->assertJsonPath('meta.total', 1)->assertJsonPath('data.0.kind', 'local');
        $this->postJson('/api/v1/marketplace-listings/'.$this->second.'/local-product', ['sku' => 'LOCAL-SKU'])->assertUnprocessable();
        $this->postJson('/api/v1/marketplace-listings/'.$this->second.'/local-product', ['sku' => 'EXPLICIT-SKU'])->assertCreated();
        $this->patchJson('/api/v1/marketplace-listings/'.$this->first.'/mapping', ['product_id' => null])->assertNoContent();
        $this->getJson('/api/v1/catalog?mapping=unmapped')->assertOk()->assertJsonPath('meta.total', 1);
    }

    public function test_catalog_has_bounded_queries_and_no_raw_payloads(): void
    {
        Product::factory()->count(25)->create(['organization_id' => $this->org->id]);
        DB::flushQueryLog();
        DB::enableQueryLog();
        $r = $this->getJson('/api/v1/catalog?per_page=100')->assertOk();
        $count = count(DB::getQueryLog());
        DB::disableQueryLog();
        $this->assertLessThan(25, $count);
        $this->assertStringNotContainsString('source_data', $r->getContent());
        foreach (range(1, 10) as $i) {
            MarketplaceAccount::factory()->create(['organization_id' => $this->org->id]);
        }DB::flushQueryLog();
        DB::enableQueryLog();
        $this->getJson('/api/v1/marketplace-accounts?per_page=100')->assertOk();
        $count = count(DB::getQueryLog());
        DB::disableQueryLog();
        $this->assertLessThan(15, $count);
    }

    public function test_foreign_ids_and_invalid_kinds_are_not_readable(): void
    {
        $u = User::factory()->create();
        $org = Organization::factory()->create();
        $u->organizations()->attach($org);
        $this->actingAs($u);
        $this->getJson('/api/v1/catalog')->assertOk()->assertJsonPath('meta.total', 0);
        foreach (['local/'.$this->local->id, 'external_variant/'.$this->first, 'invalid/1'] as $path) {
            $this->getJson('/api/v1/catalog/'.$path)->assertNotFound();
        }
    }

    public function test_demo_remains_visible_in_local_card(): void
    {
        $account = MarketplaceAccount::factory()->create(['organization_id' => $this->org->id, 'marketplace' => 'ozon', 'is_demo' => true, 'status' => 'demo']);
        MarketplaceListing::factory()->create(['organization_id' => $this->org->id, 'marketplace_account_id' => $account->id, 'product_id' => $this->local->id]);
        $this->getJson('/api/v1/catalog?is_demo=1')->assertOk()->assertJsonPath('meta.total', 1)->assertJsonPath('data.0.sources.0.account.is_demo', true);
        $this->getJson('/api/v1/catalog?kind=local')->assertOk()->assertJsonPath('meta.total', 1);
    }

    public function test_connection_probe_validates_api_without_persisting_credentials_or_account(): void
    {
        Http::preventStrayRequests();
        Http::fake(fn ($r) => str_ends_with($r->url(), '/access_token') ? Http::response(['access_token' => 'PROBE-SECRET-TOKEN', 'expires_in' => 3600]) : Http::response(['data' => ['shop' => ['name' => 'Probe shop', 'currencyCode' => 'USD'], 'currentAppInstallation' => ['accessScopes' => array_map(fn ($h) => ['handle' => $h], ['read_products', 'read_inventory', 'read_locations'])]]]));
        $before = DB::table('marketplace_accounts')->count();
        $response = $this->postJson('/api/v1/marketplace-connections/check', ['marketplace' => 'shopify', 'name' => 'Probe', 'configuration' => ['shop_domain' => 'draft.myshopify.com'], 'credentials' => ['client_id' => 'test', 'client_secret' => 'PROBE-SECRET']])->assertOk()->assertJsonPath('data.missing_scopes', []);
        $this->assertSame($before, DB::table('marketplace_accounts')->count());
        $this->assertSame(0, DB::table('marketplace_credentials')->count());
        $this->assertStringNotContainsString('PROBE-SECRET', $response->getContent());
    }

    public function test_common_detail_separates_physical_and_external_inventory_and_refresh_survives_reload(): void
    {
        app(CatalogMappingService::class)->map($this->account, $this->first, $this->local->id);
        $warehouse = Warehouse::create(['organization_id' => $this->org->id, 'name' => 'Main', 'code' => 'MAIN', 'type' => 'own', 'is_active' => true]);
        $this->postJson('/api/v1/stock-operations', ['operation_id' => (string) Str::uuid(), 'type' => 'receipt', 'warehouse_id' => $warehouse->id, 'product_id' => $this->local->id, 'quantity' => 12, 'comment' => 'Physical'])->assertCreated();
        $run = MarketplaceSyncRun::create(['id' => (string) Str::uuid(), 'organization_id' => $this->org->id, 'marketplace_account_id' => $this->account->id, 'provider' => 'shopify', 'status' => 'running', 'scope' => 'variant', 'target_listing_id' => $this->first, 'trigger' => 'manual', 'sync_products' => true, 'sync_inventory' => true, 'attempt' => 1, 'reconcile_token' => (string) Str::uuid()]);
        $service = app(MarketplaceInventoryService::class);
        $service->location($this->account, $run, new ExternalLocation('main', 'Shopify Main', true, now()->toIso8601String()));
        $service->snapshots($this->account, $run, $this->first, [new ExternalInventorySnapshot('main', true, 7, [], null, now()->toIso8601String())]);
        $run->update(['status' => 'completed']);
        $this->getJson('/api/v1/catalog/local/'.$this->local->id)->assertOk()->assertJsonPath('data.physical_stocks.0.quantity', 12)->assertJsonPath('data.sources.0.inventory.quantity', 7)->assertJsonPath('data.inventory.0.location_name', 'Shopify Main');
        $id = $this->postJson('/api/v1/marketplace-listings/'.$this->first.'/refresh')->assertAccepted()->json('data.id');
        $this->getJson('/api/v1/catalog/external_variant/'.$this->first)->assertOk()->assertJsonPath('data.sources.0.active_run.id', $id)->assertJsonPath('data.physical_stocks.0.quantity', 12);
        $this->assertSame(1, DB::table('stock_movements')->count());
    }

    public function test_parent_preview_and_gallery_are_bounded_without_losing_variant_count(): void
    {
        foreach (range(3, 25) as $id) {
            $this->source((string) $id);
        }
        $this->getJson('/api/v1/catalog?kind=external')->assertOk()->assertJsonPath('meta.total', 1)->assertJsonPath('data.0.sources_count', 25)->assertJsonCount(8, 'data.0.sources')->assertJsonCount(1, 'data.0.images');
        $parent = DB::table('marketplace_listings')->where('id', $this->first)->value('marketplace_product_id');
        $this->getJson('/api/v1/catalog/external_product/'.$parent)->assertOk()->assertJsonPath('data.sources_count', 25)->assertJsonPath('data.sources_last_page', 2)->assertJsonCount(20, 'data.sources');
        $this->getJson('/api/v1/catalog/external_product/'.$parent.'?page=2')->assertOk()->assertJsonCount(5, 'data.sources');
    }

    public function test_local_thumbnail_uses_available_external_photo_even_when_first_placement_has_none(): void
    {
        $local = Product::factory()->create(['organization_id' => $this->org->id, 'sku' => 'GALLERY-SKU']);
        $demo = MarketplaceAccount::factory()->create(['organization_id' => $this->org->id, 'marketplace' => 'ozon', 'is_demo' => true]);
        MarketplaceListing::factory()->create(['organization_id' => $this->org->id, 'marketplace_account_id' => $demo->id, 'product_id' => $local->id]);
        $imported = $this->source('30');
        app(CatalogMappingService::class)->map($this->account, $imported, $local->id);
        $this->getJson('/api/v1/catalog?search=GALLERY-SKU')->assertOk()->assertJsonPath('data.0.images.0.url', 'https://example.test/photo.png')->assertJsonPath('data.0.sources_count', 2);
    }
}
