<?php

namespace Tests\Feature;

use App\Jobs\SyncMarketplace;
use App\Jobs\SyncShopify;
use App\Models\MarketplaceAccount;
use App\Models\Organization;
use App\Models\Product;
use App\Models\ShopifySyncRun;
use App\Models\User;
use App\Services\Marketplaces\CatalogMappingService;
use App\Services\Shopify\ShopifyClient;
use App\Services\Shopify\ShopifyImporter;
use App\Services\Shopify\SyncDispatcher;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class ShopifyTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    private Organization $org;

    private MarketplaceAccount $account;

    private array $variants = [];

    private bool $failProducts = false;

    private bool $failLevels = false;

    private bool $tracked = true;

    private bool $paginate = false;

    private bool $hideProducts = false;

    private bool $brokenVariantPage = false;

    private int $failedLevelRequests = 0;

    private int $available = 7;

    private int $authCalls = 0;

    private int $unauthorized = 0;

    private int $throttled = 0;

    private array $scopes = ['read_products', 'read_inventory', 'read_locations'];

    protected function setUp(): void
    {
        parent::setUp();
        Queue::fake();
        Cache::flush();
        Http::preventStrayRequests();
        $this->org = Organization::factory()->create();
        $this->user = User::factory()->create(['active_organization_id' => $this->org->id]);
        $this->user->organizations()->attach($this->org);
        $this->actingAs($this->user);
        $id = $this->postJson('/api/v1/shopify/connections', ['name' => 'Test Shopify', 'shop_domain' => 'test-shop.myshopify.com', 'client_id' => 'client-test', 'client_secret' => 'SECRET-NEVER-EXPOSE'])->assertCreated()->json('data.id');
        $this->account = MarketplaceAccount::findOrFail($id);
        $this->variants = [$this->variant('1', 'SKU-1'), $this->variant('2', null)];
        Http::fake(fn ($request) => $this->response($request));
    }

    private function variant(string $id, ?string $sku): array
    {
        return ['id' => 'gid://shopify/ProductVariant/'.$id, 'title' => 'Variant '.$id, 'sku' => $sku, 'barcode' => null, 'price' => '12.34', 'compareAtPrice' => '15.00',
            'createdAt' => '2026-11-01T00:00:00Z', 'updatedAt' => '2026-11-12T00:00:00Z', 'inventoryItem' => ['id' => 'gid://shopify/InventoryItem/'.$id, 'tracked' => true]];
    }

    private function connection(array $nodes, bool $more = false, string $cursor = 'next'): array
    {
        return ['nodes' => $nodes, 'pageInfo' => ['hasNextPage' => $more, 'endCursor' => $cursor]];
    }

    private function response($request)
    {
        if (str_ends_with($request->url(), '/access_token')) {
            $this->authCalls++;

            return Http::response(['access_token' => 'SECRET-TOKEN', 'expires_in' => 3600]);
        }
        if ($this->unauthorized-- > 0) {
            return Http::response([], 401);
        }
        if ($this->throttled-- > 0) {
            return Http::response(['errors' => [['message' => 'limited', 'extensions' => ['code' => 'THROTTLED']]]]);
        }
        $query = $request['query'];
        $vars = $request['variables'] ?? [];
        if (str_contains($query, 'currentAppInstallation')) {
            return Http::response(['data' => ['shop' => ['name' => 'Test Shopify', 'currencyCode' => 'USD'], 'currentAppInstallation' => ['accessScopes' => array_map(fn ($s) => ['handle' => $s], $this->scopes)]]]);
        }
        if (str_contains($query, 'query TargetVariant')) {
            $variant = collect($this->variants)->firstWhere('id', $vars['id']);
            if ($variant) {
                $variant['product'] = ['id' => 'gid://shopify/Product/1', 'title' => 'Fresh Board', 'descriptionHtml' => '', 'handle' => 'board', 'vendor' => 'Vendor', 'productType' => 'Board', 'status' => 'ACTIVE', 'createdAt' => '2026-11-01T00:00:00Z', 'updatedAt' => '2026-11-12T00:00:00Z'];
            }

            return Http::response(['data' => ['productVariant' => $variant]]);
        }
        if (str_contains($query, 'query Products')) {
            if ($this->failProducts) {
                return Http::response(['errors' => [['message' => 'denied SECRET-NEVER-EXPOSE', 'extensions' => ['code' => 'ACCESS_DENIED']]]]);
            }
            $nodes = $this->variants && ! $this->hideProducts ? [['id' => 'gid://shopify/Product/1', 'title' => 'Board', 'descriptionHtml' => '<p>Snowboard</p>', 'handle' => 'board', 'vendor' => 'Vendor', 'productType' => 'Board', 'status' => 'ACTIVE', 'createdAt' => '2026-11-01T00:00:00Z', 'updatedAt' => '2026-11-12T00:00:00Z']] : [];

            return Http::response(['data' => ['products' => $this->connection($nodes)]]);
        }
        if (str_contains($query, 'query Images')) {
            return Http::response(['data' => ['product' => ['images' => $this->connection([])]]]);
        }
        if (str_contains($query, 'query Variants')) {
            if ($this->brokenVariantPage && ($vars['after'] ?? null)) {
                return Http::response(['data' => ['product' => ['variants' => $this->connection([], true, 'next')]]]);
            }
            $nodes = $this->paginate ? [($vars['after'] ?? null) ? $this->variants[1] : $this->variants[0]] : $this->variants;

            return Http::response(['data' => ['product' => ['variants' => $this->connection($nodes, $this->paginate && ! ($vars['after'] ?? null))]]]);
        }
        if (str_contains($query, 'query Locations')) {
            return Http::response(['data' => ['locations' => $this->connection([['id' => 'gid://shopify/Location/1', 'name' => 'Shop location', 'isActive' => true]])]]);
        }
        if (str_contains($query, 'query Item')) {
            return Http::response(['data' => ['inventoryItem' => ['tracked' => $this->tracked]]]);
        }
        if (str_contains($query, 'query Levels')) {
            if ($this->failedLevelRequests > 0) {
                $this->failedLevelRequests--;

                return Http::response([], 503);
            }
            if ($this->failLevels) {
                return Http::response(['errors' => [['message' => 'denied', 'extensions' => ['code' => 'ACCESS_DENIED']]]]);
            }

            return Http::response(['data' => ['inventoryItem' => ['tracked' => $this->tracked, 'inventoryLevels' => $this->connection([['id' => 'gid://shopify/InventoryLevel/1', 'updatedAt' => '2026-11-12T00:00:00Z', 'location' => ['id' => 'gid://shopify/Location/1'], 'quantities' => [['name' => 'available', 'quantity' => $this->available], ['name' => 'on_hand', 'quantity' => 9], ['name' => 'committed', 'quantity' => 2], ['name' => 'incoming', 'quantity' => 0]]]])]]]);
        }
        throw new \LogicException('Unexpected fake query');
    }

    private function sync(): ShopifySyncRun
    {
        $run = app(SyncDispatcher::class)->enqueue($this->account->fresh(), 'manual', null, true);
        (new SyncShopify($run->id))->handle(app(ShopifyImporter::class));

        return $run->fresh();
    }

    private function createLocalSkus(): void
    {
        foreach (DB::table('marketplace_listings')->where('marketplace_account_id', $this->account->id)->whereNull('product_id')->get() as $listing) {
            app(CatalogMappingService::class)->createLocalProduct($this->account, $listing->id, ['sku' => 'LOCAL-'.$listing->id]);
        }
    }

    private function url(string $suffix = ''): string
    {
        return '/api/v1/shopify/connections/'.$this->account->id.$suffix;
    }

    public function test_initial_import_variants_missing_sku_defaults_and_external_inventory(): void
    {
        $this->assertSame('hourly', $this->account->sync_frequency);
        $this->assertTrue($this->account->sync_products && $this->account->sync_inventory);
        $this->assertNull($this->account->next_sync_at);
        $this->postJson($this->url('/sync'))->assertUnprocessable();
        $run = $this->sync();
        $this->assertSame('completed', $run->status);
        $this->assertSame(2, $run->created);
        $this->assertDatabaseCount('marketplace_listings', 2);
        $this->assertDatabaseCount('products', 0);
        $this->assertDatabaseCount('shopify_inventory_levels', 2);
        $this->assertDatabaseCount('warehouse_stocks', 0);
        $this->assertDatabaseCount('stock_movements', 0);
        $this->assertSame(2, DB::table('marketplace_listings')->whereNull('product_id')->count());
        $this->assertDatabaseCount('marketplace_products', 1);
        $this->assertDatabaseHas('shopify_inventory_levels', ['available' => 7, 'on_hand' => 9, 'committed' => 2]);
        $this->assertNotNull($this->account->fresh()->next_sync_at);
        $this->getJson($this->url())->assertJsonPath('data.shopify.products_count', 1)->assertJsonPath('data.shopify.variants_count', 2);
    }

    public function test_reimport_updates_source_and_preserves_local_fields_without_duplicates(): void
    {
        $this->sync();
        $this->createLocalSkus();
        $product = Product::first();
        $product->update(['name' => 'Local edited name', 'base_price_minor' => 999]);
        $this->variants[0]['price'] = '20.01';
        $this->available = 4;
        $run = $this->sync();
        $this->assertSame('completed', $run->status);
        $this->assertDatabaseCount('products', 2);
        $this->assertDatabaseCount('marketplace_listings', 2);
        $this->assertDatabaseHas('products', ['id' => $product->id, 'name' => 'Local edited name', 'base_price_minor' => 999]);
        $this->assertDatabaseHas('marketplace_listings', ['external_id' => $this->variants[0]['id'], 'price_minor' => 2001, 'stock_quantity' => 4]);
    }

    public function test_graphql_variant_pagination_is_complete(): void
    {
        $this->paginate = true;
        $this->sync();
        $this->assertDatabaseCount('marketplace_listings', 2);
        Http::assertSent(fn ($r) => str_contains($r['query'] ?? '', 'query Variants') && ($r['variables']['after'] ?? null) === 'next');
    }

    public function test_same_sku_is_not_auto_mapped_and_manual_mapping_checks_organization(): void
    {
        $local = Product::factory()->create(['organization_id' => $this->org->id, 'sku' => 'SKU-1']);
        $this->sync();
        $listing = DB::table('marketplace_listings')->where('external_sku', 'SKU-1')->first();
        $this->assertNull($listing->product_id);
        $this->getJson($this->url('/products?mapping=unmapped'))->assertJsonPath('meta.total', 2);
        $this->patchJson($this->url('/products/'.$listing->id.'/mapping'), ['product_id' => $local->id])->assertNoContent();
        $this->assertDatabaseHas('marketplace_listings', ['id' => $listing->id, 'product_id' => $local->id]);
        $this->getJson('/api/v1/products?marketplace=shopify&marketplace_account_id='.$this->account->id)->assertJsonPath('meta.total', 1);
        $this->app['auth']->forgetGuards();
        $other = Product::factory()->create(['organization_id' => Organization::factory()->create()->id]);
        $this->actingAs($this->user);
        $this->patchJson($this->url('/products/'.$listing->id.'/mapping'), ['product_id' => $other->id])->assertNotFound();
    }

    public function test_multiple_stores_keep_ids_skus_tokens_and_history_separate(): void
    {
        $this->sync();
        $second = $this->postJson('/api/v1/shopify/connections', ['name' => 'Second', 'shop_domain' => 'second.myshopify.com', 'client_id' => 'second', 'client_secret' => 'secret-second'])->json('data.id');
        $this->account = MarketplaceAccount::find($second);
        $this->sync();
        $this->assertDatabaseCount('marketplace_listings', 4);
        $this->assertDatabaseCount('shopify_sync_runs', 2);
        $this->assertSame(2, $this->authCalls);
        $this->assertSame(4, DB::table('marketplace_listings')->whereNull('product_id')->count());
    }

    public function test_product_and_inventory_switches_are_independent(): void
    {
        $this->account->forceFill(['sync_inventory' => false])->save();
        $this->sync();
        $this->assertDatabaseCount('products', 0);
        $this->assertDatabaseCount('shopify_inventory_levels', 0);
        $this->account->forceFill(['sync_products' => false, 'sync_inventory' => true])->save();
        $this->variants[0]['price'] = '100.00';
        $this->sync();
        $this->assertDatabaseCount('shopify_inventory_levels', 2);
        $this->assertDatabaseHas('marketplace_listings', ['external_id' => $this->variants[0]['id'], 'price_minor' => 1234]);
    }

    public function test_inventory_only_first_import_discovers_unmapped_variants(): void
    {
        $this->account->forceFill(['sync_products' => false])->save();
        $this->sync();
        $this->assertDatabaseCount('products', 0);
        $this->assertDatabaseCount('marketplace_listings', 2);
        $this->assertDatabaseCount('shopify_inventory_levels', 2);
    }

    public function test_untracked_stock_is_unknown_and_is_not_zero(): void
    {
        $this->tracked = false;
        $this->sync();
        $this->assertDatabaseHas('shopify_inventory_levels', ['tracked' => false, 'available' => null, 'on_hand' => null]);
        $this->assertSame(0, DB::table('marketplace_listings')->whereNotNull('stock_quantity')->count());
    }

    public function test_full_reconciliation_marks_removed_variants_without_deleting_products(): void
    {
        $this->sync();
        $this->createLocalSkus();
        $this->variants = [$this->variants[0]];
        $this->travel(25)->hours();
        $this->sync();
        $this->assertDatabaseHas('marketplace_listings', ['external_id' => 'gid://shopify/ProductVariant/2', 'source_status' => 'deleted', 'stock_quantity' => null]);
        $this->assertDatabaseCount('products', 2);
    }

    public function test_failed_product_request_never_marks_source_deleted(): void
    {
        $this->sync();
        $this->failProducts = true;
        $this->travel(25)->hours();
        $run = $this->sync();
        $this->assertSame('partial', $run->status);
        $this->assertSame(0, DB::table('marketplace_listings')->where('source_status', 'deleted')->count());
        $this->assertStringNotContainsString('SECRET', json_encode($run->toArray()));
    }

    public function test_inventory_failure_preserves_old_snapshot_and_reports_partial(): void
    {
        $this->sync();
        $last = $this->account->fresh()->last_synced_at;
        $this->failLevels = true;
        $this->travel(1)->hours();
        $run = $this->sync();
        $this->assertSame('partial', $run->status);
        $this->assertTrue($last->equalTo($this->account->fresh()->last_synced_at));
        $this->assertDatabaseHas('shopify_inventory_levels', ['available' => 7]);
    }

    public function test_manual_dispatch_is_queued_and_concurrent_requests_reuse_run(): void
    {
        $first = $this->postJson($this->url('/sync'), ['confirm_initial_import' => true])->assertAccepted()->json('data.id');
        $second = $this->postJson($this->url('/sync'), ['confirm_initial_import' => true])->assertAccepted()->json('data.id');
        $this->assertSame($first, $second);
        Queue::assertPushed(SyncMarketplace::class, 1);
        $this->assertDatabaseCount('shopify_sync_runs', 1);
        $this->patchJson($this->url(), ['sync_frequency' => 'manual'])->assertConflict();
    }

    public function test_job_delivery_is_idempotent(): void
    {
        $run = $this->sync();
        $processed = $run->processed;
        (new SyncShopify($run->id))->handle(app(ShopifyImporter::class));
        $this->assertSame($processed, $run->fresh()->processed);
        $this->assertDatabaseCount('marketplace_listings', 2);
    }

    public function test_hourly_scheduler_only_enqueues_after_initial_import_and_due_time(): void
    {
        $this->assertSame(0, app(SyncDispatcher::class)->schedule());
        $this->sync();
        $this->assertSame(0, app(SyncDispatcher::class)->schedule());
        $this->travel(61)->minutes();
        app(SyncDispatcher::class)->schedule();
        app(SyncDispatcher::class)->schedule();
        $this->assertDatabaseCount('shopify_sync_runs', 2);
        $this->assertDatabaseHas('shopify_sync_runs', ['trigger' => 'scheduled', 'status' => 'queued']);
    }

    public function test_daily_manual_disabled_and_both_switches_off_scheduling(): void
    {
        $this->sync();
        $this->patchJson($this->url(), ['sync_frequency' => 'daily'])->assertOk();
        $this->travel(2)->hours();
        $this->assertSame(0, app(SyncDispatcher::class)->schedule());
        $this->travel(23)->hours();
        $this->assertSame(1, app(SyncDispatcher::class)->schedule());
        $run = ShopifySyncRun::where('status', 'queued')->first();
        (new SyncShopify($run->id))->handle(app(ShopifyImporter::class));
        $this->patchJson($this->url(), ['sync_frequency' => 'manual'])->assertJsonPath('data.shopify.next_sync_at', null);
        $this->travel(2)->days();
        $this->assertSame(0, app(SyncDispatcher::class)->schedule());
        $this->postJson($this->url('/sync'), [])->assertAccepted();
        ShopifySyncRun::where('status', 'queued')->update(['status' => 'completed']);
        $this->patchJson($this->url(), ['sync_frequency' => 'hourly', 'sync_inventory' => false, 'sync_products' => false])->assertJsonPath('data.shopify.next_sync_at', null);
        $this->postJson($this->url('/sync'))->assertUnprocessable();
        $this->assertSame(0, app(SyncDispatcher::class)->schedule());
    }

    public function test_token_expiry_401_and_throttle_are_retried_with_bounded_attempts(): void
    {
        $this->unauthorized = 1;
        $this->throttled = 1;
        $this->sync();
        $this->assertSame(2, $this->authCalls);
        $this->assertDatabaseHas('shopify_sync_runs', ['status' => 'completed']);
        $this->travel(2)->hours();
        app(ShopifyClient::class)->query($this->account->fresh(), 'query { shop { name } currentAppInstallation { accessScopes { handle } } }');
        $this->assertSame(3, $this->authCalls);
    }

    public function test_permission_check_secrets_encryption_and_domain_validation(): void
    {
        $this->scopes = [];
        $this->postJson($this->url('/check'))->assertOk()->assertJsonPath('data.missing_scopes.0', 'read_products');
        $response = $this->getJson($this->url())->assertOk()->getContent();
        $this->assertStringNotContainsString('SECRET', $response);
        $this->assertStringNotContainsString('client-test', $response);
        $stored = DB::table('marketplace_accounts')->value('shopify_client_secret');
        $this->assertNotSame('SECRET-NEVER-EXPOSE', $stored);
        $this->assertStringNotContainsString('SECRET-NEVER-EXPOSE', $stored);
        $this->postJson('/api/v1/shopify/connections', ['name' => 'bad', 'shop_domain' => 'localhost', 'client_id' => 'a', 'client_secret' => 'b'])->assertUnprocessable();
        $this->postJson('/api/v1/marketplace-accounts', ['name' => 'bad', 'marketplace' => 'shopify', 'status' => 'active', 'is_demo' => false])->assertUnprocessable();
    }

    public function test_organization_and_guest_isolation_for_every_endpoint(): void
    {
        $this->sync();
        $other = User::factory()->create();
        $this->actingAs($other);
        foreach (['', '/products', '/inventory', '/locations', '/runs', '/runs/'.ShopifySyncRun::withoutGlobalScopes()->first()->id] as $suffix) {
            $this->getJson($this->url($suffix))->assertNotFound();
        }
        foreach (['/check', '/sync'] as $suffix) {
            $this->postJson($this->url($suffix), ['confirm_initial_import' => true])->assertNotFound();
        }
        $this->patchJson($this->url(), ['sync_frequency' => 'manual'])->assertNotFound();
        $this->patchJson($this->url('/products/1/mapping'), ['product_id' => null])->assertNotFound();
        $this->app['auth']->forgetGuards();
        $this->getJson($this->url())->assertUnauthorized();
        $this->postJson($this->url('/sync'))->assertUnauthorized();
    }

    public function test_empty_store_is_valid_and_source_deletion_preserves_local_catalog(): void
    {
        $this->sync();
        $this->createLocalSkus();
        $this->variants = [];
        $this->travel(25)->hours();
        $run = $this->sync();
        $this->assertSame('completed', $run->status);
        $this->assertDatabaseCount('products', 2);
        $this->assertSame(2, DB::table('marketplace_listings')->where('source_status', 'deleted')->count());
    }

    public function test_a_bad_variant_keeps_valid_rows_and_returns_partial(): void
    {
        $this->variants[1]['price'] = 'broken';
        $run = $this->sync();
        $this->assertSame('partial', $run->status);
        $this->assertSame(1, $run->errors_count);
        $this->assertDatabaseCount('marketplace_listings', 1);
        $this->assertNull($this->account->fresh()->initial_import_at);
    }

    public function test_timestamps_keep_offsets_in_non_utc_database_sessions(): void
    {
        DB::statement("SET TIME ZONE 'Europe/Minsk'");
        try {
            $this->freezeTime();
            $run = $this->sync();
            $account = $this->account->fresh();
            $this->assertSame(now()->timestamp, $account->last_synced_at->timestamp);
            $this->assertSame(now()->addHour()->timestamp, $account->next_sync_at->timestamp);
            $this->assertSame(1, $run->products_processed);
            $this->assertSame(2, $run->variants_processed);
            $this->assertSame(2, $run->inventory_processed);
        } finally {
            DB::statement("SET TIME ZONE 'UTC'");
        }
    }

    public function test_point_refresh_updates_only_selected_variant_and_preserves_store_schedule(): void
    {
        $this->sync();
        $this->createLocalSkus();
        $a = $this->account->fresh();
        $listing = DB::table('marketplace_listings')->where('marketplace_account_id', $a->id)->orderBy('id')->first();
        $other = DB::table('marketplace_listings')->where('marketplace_account_id', $a->id)->orderByDesc('id')->first();
        $this->variants[0]['price'] = '24.00';
        $this->variants[1]['price'] = '99.00';
        $response = $this->postJson($this->url('/products/'.$listing->id.'/refresh'))->assertAccepted();
        $run = ShopifySyncRun::findOrFail($response->json('data.id'));
        (new SyncShopify($run->id))->handle(app(ShopifyImporter::class));
        $this->assertSame('completed', $run->fresh()->status);
        $this->assertSame(1, $run->fresh()->variants_processed);
        $this->assertSame(1, $run->fresh()->inventory_processed);
        $this->assertDatabaseHas('marketplace_listings', ['id' => $listing->id, 'price_minor' => 2400]);
        $this->assertDatabaseHas('marketplace_listings', ['id' => $other->id, 'price_minor' => 1234]);
        $this->assertDatabaseHas('products', ['id' => $listing->product_id, 'base_price_minor' => 1234]);
        $this->assertEquals($a->next_sync_at, $a->fresh()->next_sync_at);
        $this->assertEquals($a->last_products_synced_at, $a->fresh()->last_products_synced_at);
        $this->getJson($this->url('/products/'.$listing->id))->assertOk()->assertJsonPath('data.source_data.product.title', 'Fresh Board');
        $this->getJson('/api/v1/stocks?marketplace=shopify')->assertOk()->assertJsonCount(2, 'data');
    }

    public function test_point_refresh_does_not_expose_another_stores_listing_and_handles_deleted_source(): void
    {
        $this->sync();
        $this->createLocalSkus();
        $listing = DB::table('marketplace_listings')->where('marketplace_account_id', $this->account->id)->first();
        $this->getJson($this->url('/products/999999'))->assertNotFound();
        $this->postJson($this->url('/products/999999/refresh'))->assertNotFound();
        $this->variants = [];
        $response = $this->postJson($this->url('/products/'.$listing->id.'/refresh'))->assertAccepted();
        $run = ShopifySyncRun::findOrFail($response->json('data.id'));
        (new SyncShopify($run->id))->handle(app(ShopifyImporter::class));
        $this->assertSame('completed', $run->fresh()->status);
        $this->assertDatabaseHas('marketplace_listings', ['id' => $listing->id, 'source_status' => 'deleted']);
        $this->assertDatabaseCount('products', 2);
    }

    public function test_broken_pagination_never_archives_unvisited_variants(): void
    {
        $this->sync();
        $this->createLocalSkus();
        $mapping = DB::table('marketplace_listings')->orderBy('id')->pluck('product_id', 'id')->all();
        $this->paginate = true;
        $this->brokenVariantPage = true;
        $this->travel(25)->hours();
        $run = $this->sync();
        $this->assertSame('partial', $run->status);
        $this->assertSame(0, DB::table('marketplace_listings')->where('source_status', 'deleted')->count());
        $this->assertSame($mapping, DB::table('marketplace_listings')->orderBy('id')->pluck('product_id', 'id')->all());
    }

    public function test_actual_shopify_retry_replays_scan_without_inflated_unique_counters(): void
    {
        $this->failedLevelRequests = 4;
        $run = $this->sync();
        $this->assertSame('queued', $run->status);
        $this->assertSame(2, $run->created);
        $this->travel(61)->seconds();
        app(\App\Services\Marketplaces\SyncDispatcher::class)->recover();
        (new SyncMarketplace($run->id))->handle();
        $run->refresh();
        $this->assertSame('completed', $run->status);
        $this->assertSame(2, $run->attempt);
        $this->assertSame(2, $run->created);
        $this->assertSame(4, $run->processed);
        $this->assertSame(2, $run->variants_processed);
        $this->assertSame(2, $run->inventory_processed);
        $this->assertSame(0, $run->errors_count);
        $this->assertNull($run->error_message);
        $this->assertCount(1, $run->attempt_errors);
        $this->assertDatabaseCount('marketplace_listings', 2);
    }

    public function test_disappearance_in_mutable_scan_is_verified_before_archiving(): void
    {
        $this->sync();
        $this->hideProducts = true;
        $this->travel(25)->hours();
        $run = $this->sync();
        $this->assertSame('queued', $run->status);
        $this->assertSame(0, DB::table('marketplace_listings')->where('source_status', 'deleted')->count());
        $this->assertSame('source_changed', $run->attempt_errors[0]['code']);
        $this->hideProducts = false;
        $this->travel(61)->seconds();
        (new SyncMarketplace($run->id))->handle();
        $this->assertSame('completed', $run->fresh()->status);
    }
}
