<?php

namespace Tests\Feature;

use App\Models\MarketplaceAccount;
use App\Models\MarketplaceCredential;
use App\Models\MarketplaceListing;
use App\Models\MarketplaceProduct;
use App\Models\Organization;
use App\Models\Product;
use App\Models\User;
use App\Services\Marketplaces\CatalogMappingService;
use App\Services\Marketplaces\DemoProvider;
use App\Services\Marketplaces\MarketplaceBackfill;
use App\Services\Marketplaces\MarketplaceImportService;
use App\Services\Marketplaces\ProviderRegistry;
use Database\Seeders\DemoDataSeeder;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Tests\TestCase;

class MarketplaceFoundationTest extends TestCase
{
    use RefreshDatabase;

    private Organization $org;

    private User $user;

    private MarketplaceAccount $account;

    protected function setUp(): void
    {
        parent::setUp();
        Http::preventStrayRequests();
        $this->org = Organization::factory()->create();
        $this->user = User::factory()->create(['active_organization_id' => $this->org->id]);
        $this->user->organizations()->attach($this->org);
        $this->actingAs($this->user);
        $id = $this->postJson('/api/v1/marketplace-accounts', $this->connection())->assertCreated()->json('data.id');
        $this->account = MarketplaceAccount::findOrFail($id);
        $this->account->forceFill(['shop_currency' => 'USD'])->save();
    }

    private function connection(array $extra = []): array
    {
        return [...['marketplace' => 'shopify', 'name' => 'Store', 'configuration' => ['shop_domain' => 'foundation.myshopify.com'], 'credentials' => ['client_id' => 'client-foundation', 'client_secret' => 'SECRET-FOUNDATION']], ...$extra];
    }

    private function raw(string $variant = '1', string $title = 'External board'): array
    {
        return ['product' => ['id' => 'gid://shopify/Product/10', 'title' => $title, 'status' => 'ACTIVE', 'descriptionHtml' => '<p>Details</p>', 'images' => [], 'updatedAt' => '2026-11-12T09:00:00Z'],
            'variant' => ['id' => 'gid://shopify/ProductVariant/'.$variant, 'title' => 'Blue', 'sku' => 'SAME-SKU', 'barcode' => '123', 'price' => '12.34', 'inventoryItem' => ['id' => 'gid://shopify/InventoryItem/'.$variant, 'tracked' => true]]];
    }

    private function imported(string $variant = '1', ?MarketplaceAccount $account = null): MarketplaceListing
    {
        $account ??= $this->account;
        $raw = $this->raw($variant);
        $normalize = app(ProviderRegistry::class)->get($account->marketplace)->sourceNormalizer();
        $data = $normalize($account, $raw, now()->toIso8601String());
        $result = app(MarketplaceImportService::class)->upsert($account, $data['parent'], $data['variant'], $raw);

        return MarketplaceListing::findOrFail($result['id']);
    }

    public function test_common_connections_registry_and_legacy_wrappers_share_credentials_and_settings(): void
    {
        $this->getJson('/api/v1/marketplace-providers')->assertOk()->assertJsonCount(4, 'data');
        $this->patchJson('/api/v1/marketplace-accounts/'.$this->account->id, ['sync_frequency' => 'manual', 'sync_inventory' => false])->assertOk();
        $this->patchJson('/api/v1/shopify/connections/'.$this->account->id, ['client_secret' => 'ROTATED-SECRET'])->assertOk();
        $this->assertDatabaseCount('marketplace_accounts', 1);
        $this->assertDatabaseCount('marketplace_credentials', 1);
        $credential = MarketplaceCredential::firstOrFail();
        $this->assertSame(2, $credential->version);
        $this->assertSame('ROTATED-SECRET', $credential->payload['client_secret']);
        $this->assertSame('ROTATED-SECRET', $this->account->fresh()->shopify_client_secret);
        $this->getJson('/api/v1/shopify/connections/'.$this->account->id)->assertJsonPath('data.shopify.sync_frequency', 'manual')->assertJsonPath('data.shopify.sync_inventory', false);
        $stored = DB::table('marketplace_credentials')->value('payload');
        $this->assertStringNotContainsString('ROTATED-SECRET', $stored);
        $this->assertStringNotContainsString('ROTATED-SECRET', json_encode($credential));
        foreach (['/marketplace-accounts', '/marketplace-accounts/'.$this->account->id, '/shopify/connections/'.$this->account->id] as $url) {
            $body = $this->getJson('/api/v1'.$url)->assertOk()->getContent();
            $this->assertStringNotContainsString('ROTATED-SECRET', $body);
            $this->assertStringNotContainsString('SECRET-FOUNDATION', $body);
        }
        $this->postJson('/api/v1/marketplace-accounts/'.$this->account->id.'/disconnect')->assertOk()->assertJsonPath('data.status', 'disabled');
    }

    public function test_demo_providers_do_not_call_api_or_accept_real_credentials(): void
    {
        Http::fake();
        foreach (['wildberries', 'ozon'] as $code) {
            $id = $this->postJson('/api/v1/marketplace-accounts', ['marketplace' => $code, 'name' => $code])->assertCreated()->assertJsonPath('data.is_demo', true)->json('data.id');
            $this->postJson('/api/v1/marketplace-accounts/'.$id.'/check')->assertUnprocessable();
            $this->assertNull(app(ProviderRegistry::class)->get($code)->importer());
            $this->postJson('/api/v1/marketplace-accounts', ['marketplace' => $code, 'name' => $code, 'credentials' => ['api_key' => 'UNSUPPORTED']])->assertUnprocessable();
        }
        Http::assertNothingSent();
    }

    public function test_backfill_preserves_local_ids_mapping_demo_and_settings_and_is_repeatable(): void
    {
        $this->app['auth']->forgetGuards();
        $this->seed(DemoDataSeeder::class);
        $this->actingAs($this->user);
        $local = Product::factory()->create(['organization_id' => $this->org->id, 'sku' => 'USER-SKU']);
        $this->account->forceFill(['sync_frequency' => 'manual', 'sync_inventory' => false])->save();
        foreach (['1', '2'] as $id) {
            DB::table('marketplace_listings')->insert(['organization_id' => $this->org->id, 'marketplace_account_id' => $this->account->id, 'product_id' => $id === '1' ? $local->id : null,
                'external_id' => 'gid://shopify/ProductVariant/'.$id, 'price_minor' => 1234, 'currency' => 'USD', 'stock_quantity' => null, 'status' => 'active', 'source_status' => 'active',
                'source_data' => json_encode($this->raw($id)), 'source_synced_at' => now()->toIso8601String(), 'created_at' => now()->toIso8601String(), 'updated_at' => now()->toIso8601String()]);
        }
        DB::table('marketplace_credentials')->where('marketplace_account_id', $this->account->id)->delete();
        $products = DB::table('products')->orderBy('id')->get();
        $identity = DB::table('marketplace_listings')->orderBy('id')->get(['id', 'product_id', 'marketplace_account_id', 'external_id', 'source_data', 'updated_at']);
        $first = app(MarketplaceBackfill::class)->run();
        $this->assertSame(2, $first['variants_processed']);
        $this->assertSame(1, $first['credentials_created']);
        $this->assertSame(0, $first['skipped']);
        $this->assertEquals($products, DB::table('products')->orderBy('id')->get());
        $this->assertEquals($identity, DB::table('marketplace_listings')->orderBy('id')->get(['id', 'product_id', 'marketplace_account_id', 'external_id', 'source_data', 'updated_at']));
        $parentIds = MarketplaceProduct::pluck('id')->all();
        $this->assertDatabaseCount('marketplace_products', 1);
        $this->assertSame(2, MarketplaceProduct::firstOrFail()->listings()->count());
        $again = app(MarketplaceBackfill::class)->run();
        $this->assertSame(0, $again['credentials_created']);
        $this->assertSame($parentIds, MarketplaceProduct::pluck('id')->all());
        $this->assertSame('manual', $this->account->fresh()->sync_frequency);
        $this->assertFalse($this->account->fresh()->sync_inventory);
    }

    public function test_invalid_source_is_reported_without_deleting_the_original_record(): void
    {
        $listing = $this->imported();
        DB::table('marketplace_listings')->where('id', $listing->id)->update(['source_data' => json_encode(['product' => null])]);
        $result = app(MarketplaceBackfill::class)->run();
        $this->assertSame(1, $result['skipped']);
        $this->assertDatabaseHas('marketplace_listings', ['id' => $listing->id]);
        $this->assertSame(['product' => null], $listing->fresh()->source_data);
    }

    public function test_normalized_read_api_includes_unmapped_variants_and_searches_source_fields(): void
    {
        $listing = $this->imported();
        $this->getJson('/api/v1/products')->assertJsonPath('meta.total', 0);
        $this->getJson('/api/v1/marketplace-listings?mapping=unmapped&search=External')->assertOk()->assertJsonPath('meta.total', 1)->assertJsonPath('data.0.external_product.title', 'External board')->assertJsonPath('data.0.barcode', '123');
        $this->getJson('/api/v1/stocks?search=SAME-SKU')->assertJsonPath('meta.total', 1);
        $this->getJson('/api/v1/marketplace-listings/'.$listing->id)->assertOk()->assertJsonPath('data.product', null);
        $this->assertDatabaseCount('products', 0);
    }

    public function test_mapping_unmapping_and_explicit_creation_never_auto_match_sku(): void
    {
        $local = Product::factory()->create(['organization_id' => $this->org->id, 'sku' => 'SAME-SKU']);
        $listing = $this->imported();
        $this->assertNull($listing->product_id);
        $url = '/api/v1/marketplace-listings/'.$listing->id;
        $this->patchJson($url.'/mapping', ['product_id' => $local->id])->assertNoContent();
        $this->imported();
        $this->assertSame($local->id, $listing->fresh()->product_id);
        $this->patchJson($url.'/mapping', ['product_id' => null])->assertNoContent();
        $this->imported();
        $this->assertNull($listing->fresh()->product_id);
        $created = $this->postJson($url.'/local-product', ['sku' => 'USER-EXPLICIT'])->assertCreated()->json('data.id');
        $this->assertSame($created, $listing->fresh()->product_id);
        $this->postJson($url.'/local-product', ['sku' => 'SECOND-ATTEMPT'])->assertConflict();
        $this->assertDatabaseCount('products', 2);
    }

    public function test_two_stores_keep_external_identity_and_same_sku_separate(): void
    {
        $first = $this->imported();
        $id = $this->postJson('/api/v1/marketplace-accounts', $this->connection(['configuration' => ['shop_domain' => 'second-foundation.myshopify.com']]))->assertCreated()->json('data.id');
        $account = MarketplaceAccount::findOrFail($id);
        $account->forceFill(['shop_currency' => 'USD'])->save();
        $second = $this->imported('1', $account);
        $this->assertNotSame($first->marketplace_product_id, $second->marketplace_product_id);
        $this->assertNull($second->product_id);
        $this->assertDatabaseCount('products', 0);
        $this->expectException(ModelNotFoundException::class);
        app(CatalogMappingService::class)->map($account, $first->id, null);
    }

    public function test_foreign_organization_cannot_read_map_or_update_credentials(): void
    {
        $listing = $this->imported();
        $other = User::factory()->create();
        $this->actingAs($other);
        $this->getJson('/api/v1/marketplace-listings/'.$listing->id)->assertNotFound();
        $this->patchJson('/api/v1/marketplace-listings/'.$listing->id.'/mapping', ['product_id' => null])->assertNotFound();
        $this->postJson('/api/v1/marketplace-listings/'.$listing->id.'/local-product', ['sku' => 'OTHER'])->assertNotFound();
        $this->patchJson('/api/v1/marketplace-accounts/'.$this->account->id, ['credentials' => ['client_secret' => 'OTHER']])->assertNotFound();
    }

    public function test_new_api_key_provider_only_requires_adapter_registration(): void
    {
        config(['marketplaces.providers.test_api_key' => ['adapter' => TestApiKeyProvider::class, 'name' => 'Test API Key']]);
        $id = $this->postJson('/api/v1/marketplace-accounts', ['marketplace' => 'test_api_key', 'name' => 'Future provider', 'credentials' => ['api_key' => 'PRIVATE-KEY']])->assertCreated()->assertJsonPath('data.provider.auth_type', 'api_key')->json('data.id');
        $this->postJson('/api/v1/marketplace-accounts/'.$id.'/check')->assertOk()->assertJsonPath('data.connected', true);
        $this->assertStringNotContainsString('PRIVATE-KEY', DB::table('marketplace_credentials')->where('marketplace_account_id', $id)->value('payload'));
        $this->getJson('/api/v1/marketplace-accounts?marketplace=test_api_key')->assertOk()->assertJsonPath('meta.total', 1);
    }

    public function test_check_failure_does_not_expose_provider_response_or_credentials_in_logs(): void
    {
        $messages = [];
        Log::listen(function ($event) use (&$messages) {
            $messages[] = $event->message;
        });
        Http::fake(['*/access_token' => Http::response(['access_token' => 'SECRET-TOKEN']), '*' => Http::response(['errors' => [['message' => 'SECRET-FOUNDATION', 'extensions' => ['code' => 'ACCESS_DENIED']]]])]);
        $body = $this->postJson('/api/v1/marketplace-accounts/'.$this->account->id.'/check')->assertUnprocessable()->getContent();
        foreach (['SECRET-FOUNDATION', 'SECRET-TOKEN'] as $secret) {
            $this->assertStringNotContainsString($secret, $body);
            $this->assertStringNotContainsString($secret, json_encode($messages));
        }
    }
}

class TestApiKeyProvider extends DemoProvider
{
    public function definition(): array
    {
        return array_replace(parent::definition(), ['connection_type' => 'api_key', 'auth_type' => 'api_key', 'capabilities' => ['check_connection'], 'credentials_schema' => [['key' => 'api_key', 'write_only' => true]]]);
    }

    public function credentialRules(): array
    {
        return ['api_key' => ['required', 'string']];
    }

    public function connectionChecker(): ?\Closure
    {
        return fn () => ['connected' => true];
    }
}
