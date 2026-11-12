<?php

namespace Tests\Feature;

use App\Models\MarketplaceAccount;
use App\Models\MarketplaceListing;
use App\Models\MarketplaceSyncRun;
use App\Models\Organization;
use App\Models\Product;
use App\Models\User;
use App\Models\Warehouse;
use App\Services\Marketplaces\ExternalInventorySnapshot;
use App\Services\Marketplaces\ExternalLocation;
use App\Services\Marketplaces\MarketplaceImportService;
use App\Services\Marketplaces\MarketplaceInventoryService;
use App\Services\Marketplaces\SyncException;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use PhpOffice\PhpSpreadsheet\IOFactory;
use Tests\TestCase;

class MarketplaceInventoryTest extends TestCase
{
    use RefreshDatabase;

    private MarketplaceAccount $account;

    private MarketplaceSyncRun $run;

    private MarketplaceInventoryService $store;

    private int $listing;

    protected function setUp(): void
    {
        parent::setUp();
        Queue::fake();
        $org = Organization::factory()->create();
        $user = User::factory()->create(['active_organization_id' => $org->id]);
        $user->organizations()->attach($org);
        $this->actingAs($user);
        $this->account = MarketplaceAccount::factory()->create(['organization_id' => $org->id, 'marketplace' => 'shopify', 'is_demo' => false, 'status' => 'active']);
        $this->store = app(MarketplaceInventoryService::class);
        $this->listing = $this->listing($this->account);
        $this->run = $this->newRun($this->account);
    }

    private function listing(MarketplaceAccount $account): int
    {
        return app(MarketplaceImportService::class)->upsert($account, ['external_id' => 'parent', 'title' => 'External Test Title', 'status' => 'active', 'images' => [], 'source_data' => []], ['external_id' => 'variant', 'external_sku' => 'SHARED-SKU', 'barcode' => '123456789', 'price_minor' => 100, 'currency' => 'USD', 'status' => 'active'], [])['id'];
    }

    private function newRun(MarketplaceAccount $account): MarketplaceSyncRun
    {
        return MarketplaceSyncRun::create(['id' => (string) Str::uuid(), 'organization_id' => $account->organization_id, 'marketplace_account_id' => $account->id, 'provider' => $account->marketplace, 'trigger' => 'manual', 'scope' => 'account', 'status' => 'running', 'sync_products' => false, 'sync_inventory' => true, 'attempt' => 1, 'reconcile_token' => (string) Str::uuid(), 'attempt_started_at' => now()]);
    }

    private function location(string $id = 'loc', bool $active = true): int
    {
        return $this->store->location($this->account, $this->run, new ExternalLocation($id, 'Location '.$id, $active, now()->toIso8601String()));
    }

    private function snapshot(string $id = 'loc', ?int $quantity = 7, bool $tracked = true): ExternalInventorySnapshot
    {
        return new ExternalInventorySnapshot($id, $tracked, $quantity, ['available' => $quantity, 'reserved' => 3], null, now()->toIso8601String());
    }

    public function test_upserts_preserve_identity_mapping_and_physical_stock(): void
    {
        $before = DB::table('stock_movements')->count();
        $local = Warehouse::create(['organization_id' => $this->account->organization_id, 'name' => 'Main', 'code' => 'MAIN', 'type' => 'own', 'is_active' => true]);
        $product = Product::factory()->create(['organization_id' => $this->account->organization_id]);
        $this->postJson('/api/v1/stock-operations', ['operation_id' => (string) Str::uuid(), 'type' => 'receipt', 'warehouse_id' => $local->id, 'product_id' => $product->id, 'quantity' => 12, 'comment' => 'Physical receipt'])->assertCreated();
        $physical = DB::table('warehouse_stocks')->get()->toJson();
        $movements = DB::table('stock_movements')->get()->toJson();
        $id = $this->location();
        DB::table('marketplace_locations')->where('id', $id)->update(['warehouse_id' => $local->id]);
        $this->store->snapshots($this->account, $this->run, $this->listing, [$this->snapshot()]);
        $level = DB::table('marketplace_inventory_levels')->first();
        $this->assertSame($id, $this->location());
        $this->store->snapshots($this->account, $this->run, $this->listing, [$this->snapshot(quantity: 0)]);
        $this->assertSame($level->id, DB::table('marketplace_inventory_levels')->sole()->id);
        $this->assertSame($local->id, DB::table('marketplace_locations')->value('warehouse_id'));
        $this->assertSame($physical, DB::table('warehouse_stocks')->get()->toJson());
        $this->assertSame($movements, DB::table('stock_movements')->get()->toJson());
        $this->assertNull(DB::table('marketplace_listings')->where('id', $this->listing)->value('product_id'));
    }

    public function test_unknown_zero_negative_and_untracked_are_distinct(): void
    {
        foreach ([['zero', 0, true], ['unknown', null, true], ['negative', -4, true], ['untracked', 99, false]] as [$id,$qty,$tracked]) {
            $this->location($id);
            $this->store->snapshots($this->account, $this->run, $this->listing, [$this->snapshot($id, $qty, $tracked)]);
        }
        $this->getJson('/api/v1/marketplace-inventory')->assertOk()->assertJsonPath('meta.total', 4);
        $this->getJson('/api/v1/marketplace-inventory?stock=zero')->assertOk()->assertJsonPath('meta.total', 1)->assertJsonPath('data.0.stock_quantity', 0);
        $this->getJson('/api/v1/marketplace-inventory?stock=negative')->assertOk()->assertJsonPath('data.0.stock_quantity', -4);
        $this->getJson('/api/v1/marketplace-inventory?stock=unknown')->assertOk()->assertJsonPath('meta.total', 2);
        $this->getJson('/api/v1/marketplace-inventory?tracked=0')->assertOk()->assertJsonPath('data.0.tracked', false)->assertJsonPath('data.0.stock_quantity', null);
    }

    public function test_two_stores_and_locations_are_filtered_without_double_counting(): void
    {
        $a = $this->location('one');
        $b = $this->location('two');
        $this->store->snapshots($this->account, $this->run, $this->listing, [$this->snapshot('one', 3), $this->snapshot('two', 4)]);
        $other = MarketplaceAccount::factory()->create(['organization_id' => $this->account->organization_id, 'marketplace' => 'shopify', 'is_demo' => false, 'status' => 'active']);
        $listing = $this->listing($other);
        $run = $this->newRun($other);
        $this->store->location($other, $run, new ExternalLocation('one', 'Other store', true, now()->toIso8601String()));
        $this->store->snapshots($other, $run, $listing, [$this->snapshot('one', 20)]);
        DB::table('marketplace_listings')->where('id', $this->listing)->update(['stock_quantity' => 700]);
        $this->getJson('/api/v1/stocks?provider=shopify&marketplace_account_id='.$this->account->id)->assertOk()->assertJsonPath('meta.total', 2);
        $this->getJson('/api/v1/stocks?location_id='.$a)->assertOk()->assertJsonPath('meta.total', 1)->assertJsonPath('data.0.stock_quantity', 3);
        $this->getJson('/api/v1/marketplace-accounts/'.$other->id.'/inventory')->assertOk()->assertJsonPath('meta.total', 1)->assertJsonPath('data.0.stock_quantity', 20);
        foreach (['External Test Title', 'SHARED-SKU', '123456789'] as $search) {
            $this->getJson('/api/v1/stocks?mapping=unmapped&search='.urlencode($search))->assertOk()->assertJsonPath('meta.total', 3);
        }
    }

    public function test_full_reconcile_retains_values_and_partial_scan_does_not_remove_anything(): void
    {
        $this->location('one');
        $this->location('two');
        $this->store->snapshots($this->account, $this->run, $this->listing, [$this->snapshot('one', 3), $this->snapshot('two', 4)]);
        $this->run->update(['status' => 'completed']);
        $this->run = $this->newRun($this->account);
        $this->store->snapshots($this->account, $this->run, $this->listing, [$this->snapshot('one', 5)]);
        $this->store->reconcileListing($this->account, $this->run, $this->listing, false);
        $this->assertSame(2, DB::table('marketplace_inventory_levels')->where('is_current', true)->count());
        $this->store->reconcileListing($this->account, $this->run, $this->listing, true);
        $this->getJson('/api/v1/stocks?is_current=0')->assertOk()->assertJsonPath('meta.total', 1)->assertJsonPath('data.0.stock_quantity', 4)->assertJsonPath('data.0.stale_reason', 'missing');
    }

    public function test_point_scope_does_not_reconcile_other_locations_or_listings(): void
    {
        $this->location('one');
        $this->location('two');
        $this->store->snapshots($this->account, $this->run, $this->listing, [$this->snapshot('one')]);
        $this->run->update(['scope' => 'variant', 'target_listing_id' => $this->listing]);
        $this->store->reconcileLocations($this->account, $this->run, true);
        $this->assertSame(2, DB::table('marketplace_locations')->where('is_active', true)->count());
        $this->store->stale($this->account, $this->run, $this->listing, 'api_error');
        $this->assertSame(7, DB::table('marketplace_inventory_levels')->value('available'));
        $this->assertFalse(DB::table('marketplace_inventory_levels')->value('is_current'));
    }

    public function test_inactive_and_missing_locations_keep_last_known_values(): void
    {
        $this->location('one', false);
        $this->location('two');
        $this->store->snapshots($this->account, $this->run, $this->listing, [$this->snapshot('one', -2), $this->snapshot('two', 8)]);
        $this->run->update(['status' => 'completed']);
        $this->run = $this->newRun($this->account);
        $this->location('one', false);
        $this->store->reconcileLocations($this->account, $this->run, true);
        $this->getJson('/api/v1/stocks?location_active=0')->assertOk()->assertJsonPath('meta.total', 2);
        $this->assertSame([-2, 8], DB::table('marketplace_inventory_levels')->orderBy('id')->pluck('available')->all());
    }

    public function test_mapping_unmapping_authorization_and_foreign_tenants(): void
    {
        $id = $this->location();
        $warehouse = Warehouse::create(['organization_id' => $this->account->organization_id, 'name' => 'Main', 'code' => 'MAIN', 'type' => 'own', 'is_active' => true]);
        $this->patchJson('/api/v1/marketplace-locations/'.$id.'/mapping', ['warehouse_id' => $warehouse->id])->assertOk()->assertJsonPath('data.warehouse_id', $warehouse->id);
        $this->patchJson('/api/v1/marketplace-locations/'.$id.'/mapping', ['warehouse_id' => null])->assertOk()->assertJsonPath('data.warehouse_id', null);
        $foreign = Organization::factory()->create();
        $warehouse = DB::table('warehouses')->insertGetId(['organization_id' => $foreign->id, 'name' => 'Foreign', 'code' => 'foreign', 'type' => 'own', 'is_active' => true, 'created_at' => now(), 'updated_at' => now()]);
        $this->patchJson('/api/v1/marketplace-locations/'.$id.'/mapping', ['warehouse_id' => $warehouse])->assertNotFound();
        Gate::before(fn () => false);
        $this->patchJson('/api/v1/marketplace-locations/'.$id.'/mapping', ['warehouse_id' => null])->assertForbidden();
    }

    public function test_organization_isolation_and_foreign_account_targets(): void
    {
        $id = $this->location();
        $this->store->snapshots($this->account, $this->run, $this->listing, [$this->snapshot()]);
        $user = User::factory()->create();
        $user->organizations()->attach(Organization::factory()->create());
        $this->actingAs($user);
        $this->getJson('/api/v1/stocks')->assertOk()->assertJsonPath('meta.total', 0);
        $this->getJson('/api/v1/marketplace-accounts/'.$this->account->id.'/locations')->assertNotFound();
        $this->patchJson('/api/v1/marketplace-locations/'.$id.'/mapping', ['warehouse_id' => null])->assertNotFound();
    }

    public function test_stale_execution_lease_cannot_write_after_model_refresh(): void
    {
        $this->run->bindExecutionLease($this->run->reconcile_token);
        DB::table('marketplace_sync_runs')->where('id', $this->run->id)->update(['reconcile_token' => (string) Str::uuid()]);
        $this->run->refresh();
        try {
            $this->location();
            $this->fail('Stale execution wrote a location');
        } catch (SyncException $e) {
            $this->assertSame('stale_attempt', $e->reason);
        }
        $this->assertSame(0, DB::table('marketplace_locations')->count());
    }

    public function test_signed_snapshots_and_location_provenance_are_exported(): void
    {
        $this->location();
        $this->store->snapshots($this->account, $this->run, $this->listing, [$this->snapshot(quantity: -3)]);
        $response = $this->getJson('/api/v1/stocks/export?provider=shopify')->assertOk();
        $path = $response->baseResponse->getFile()->getPathname();
        try {
            $sheet = IOFactory::load($path)->getActiveSheet();
            $this->assertSame(-3, $sheet->getCell('H2')->getValue());
            $this->assertSame('Location loc', $sheet->getCell('N2')->getValue());
            $this->assertSame('Внешний снимок', $sheet->getCell('O2')->getValue());
        } finally {
            @unlink($path);
        }
    }

    public function test_legacy_views_keep_ids_and_response_aliases(): void
    {
        $id = $this->location();
        $this->store->snapshots($this->account, $this->run, $this->listing, [$this->snapshot()]);
        $this->assertSame($id, DB::table('shopify_locations')->value('id'));
        $this->assertSame($id, DB::table('shopify_inventory_levels')->value('shopify_location_id'));
        $this->getJson('/api/v1/shopify/connections/'.$this->account->id.'/inventory')->assertOk()->assertJsonPath('data.0.shopify_location_id', $id)->assertJsonPath('data.0.title', 'External Test Title');
    }

    public function test_demo_stock_stays_undetailed_and_is_not_combined_with_real_levels(): void
    {
        $a = MarketplaceAccount::factory()->create(['organization_id' => $this->account->organization_id, 'marketplace' => 'ozon', 'is_demo' => true, 'status' => 'demo']);
        $product = Product::factory()->create(['organization_id' => $this->account->organization_id]);
        MarketplaceListing::factory()->create(['organization_id' => $this->account->organization_id, 'marketplace_account_id' => $a->id, 'product_id' => $product->id, 'stock_quantity' => 9]);
        $this->location();
        $this->store->snapshots($this->account, $this->run, $this->listing, [$this->snapshot()]);
        $this->getJson('/api/v1/stocks?provider=ozon')->assertOk()->assertJsonPath('meta.total', 1)->assertJsonPath('data.0.stock_quantity', 9)->assertJsonPath('data.0.location_name', null)->assertJsonPath('data.0.inventory_origin', 'demo');
        $this->getJson('/api/v1/marketplace-accounts/'.$a->id.'/locations')->assertOk()->assertJsonCount(0, 'data');
    }

    public function test_composite_foreign_keys_reject_cross_account_listing_and_warehouse(): void
    {
        $id = $this->location();
        $this->store->snapshots($this->account, $this->run, $this->listing, [$this->snapshot()]);
        $other = MarketplaceAccount::factory()->create(['organization_id' => $this->account->organization_id, 'marketplace' => 'shopify', 'is_demo' => false, 'status' => 'active']);
        $listing = $this->listing($other);
        try {
            DB::transaction(fn () => DB::table('marketplace_inventory_levels')->where('marketplace_listing_id', $this->listing)->update(['marketplace_listing_id' => $listing]));
            $this->fail('Cross account FK accepted');
        } catch (QueryException $e) {
            $this->assertSame('23503', $e->errorInfo[0]);
        }
        try {
            DB::transaction(fn () => DB::table('marketplace_inventory_levels')->insert([...get_object_vars(DB::table('marketplace_inventory_levels')->first()), 'id' => 99999]));
            $this->fail('Duplicate accepted');
        } catch (QueryException $e) {
            $this->assertSame('23505', $e->errorInfo[0]);
        }
        $foreign = Organization::factory()->create();
        $warehouse = DB::table('warehouses')->insertGetId(['organization_id' => $foreign->id, 'name' => 'Foreign', 'code' => 'F', 'type' => 'own', 'is_active' => true]);
        try {
            DB::transaction(fn () => DB::table('marketplace_locations')->where('id', $id)->update(['warehouse_id' => $warehouse]));
            $this->fail('Foreign warehouse accepted');
        } catch (QueryException $e) {
            $this->assertSame('23503', $e->errorInfo[0]);
        }
    }

    public function test_api_query_count_is_bounded_and_secrets_are_not_serialized(): void
    {
        foreach (range(1, 12) as $i) {
            $this->location((string) $i);
            $this->store->snapshots($this->account, $this->run, $this->listing, [$this->snapshot((string) $i)]);
        }
        DB::enableQueryLog();
        $response = $this->getJson('/api/v1/stocks?per_page=100')->assertOk()->assertJsonPath('meta.total', 12);
        $count = count(DB::getQueryLog());
        DB::disableQueryLog();
        $this->assertLessThan(20, $count);
        $this->assertStringNotContainsString('source_data', $response->getContent());
        $this->assertStringNotContainsString('client_secret', $response->getContent());
    }

    public function test_snapshot_page_is_atomic_and_older_source_cannot_overwrite_current_value(): void
    {
        $this->location();
        try {
            $this->store->snapshots($this->account, $this->run, $this->listing, [$this->snapshot(), $this->snapshot('missing')]);
            $this->fail('Invalid page accepted');
        } catch (SyncException $e) {
            $this->assertSame('invalid_data', $e->reason);
        }
        $this->assertSame(0, DB::table('marketplace_inventory_levels')->count());
        $this->store->snapshots($this->account, $this->run, $this->listing, [$this->snapshot(quantity: 9)]);
        try {
            $this->store->snapshots($this->account, $this->run, $this->listing, [new ExternalInventorySnapshot('loc', true, 1, [], null, '2020-01-01T00:00:00Z')]);
            $this->fail('Older source accepted');
        } catch (SyncException $e) {
            $this->assertSame('source_changed', $e->reason);
        }
        $this->assertSame(9, DB::table('marketplace_inventory_levels')->value('available'));
    }
}
