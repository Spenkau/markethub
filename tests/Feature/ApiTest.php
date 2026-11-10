<?php

namespace Tests\Feature;

use App\Models\MarketplaceAccount;
use App\Models\MarketplaceListing;
use App\Models\Organization;
use App\Models\Product;
use App\Models\User;
use Database\Seeders\DemoDataSeeder;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class ApiTest extends TestCase
{
    use RefreshDatabase;

    private Organization $organization;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();
        $this->organization = Organization::factory()->create();
        $this->user = User::factory()->create();
        $this->user->organizations()->attach($this->organization);
    }

    private function product(array $attributes = [], ?Organization $organization = null): Product
    {
        return Product::factory()->create(['organization_id' => ($organization ?? $this->organization)->id, ...$attributes]);
    }

    private function account(?Organization $organization = null): MarketplaceAccount
    {
        return MarketplaceAccount::factory()->create(['organization_id' => ($organization ?? $this->organization)->id]);
    }

    private function productPayload(array $attributes = []): array
    {
        return ['sku' => 'SKU-NEW', 'name' => 'Новый товар', 'description' => 'Описание', 'price_minor' => 199999, 'currency' => 'RUB', 'is_active' => true, ...$attributes];
    }

    private function listingPayload(Product $product, MarketplaceAccount $account, array $attributes = []): array
    {
        return ['product_id' => $product->id, 'marketplace_account_id' => $account->id, 'external_id' => 'SYNTHETIC-TEST', 'external_sku' => 'TEST-1', 'price_minor' => 15000, 'currency' => 'RUB', 'stock_quantity' => 3, 'status' => 'active', ...$attributes];
    }

    public static function privateEndpoints(): array
    {
        return array_map(fn ($path) => [$path], ['products', 'marketplace-accounts', 'marketplace-listings', 'stocks', 'organizations', 'auth/me']);
    }

    #[DataProvider('privateEndpoints')]
    public function test_anonymous_access_is_json_401(string $path): void
    {
        $this->get('/api/v1/'.$path)->assertUnauthorized()->assertJsonStructure(['message']);
    }

    public function test_login_me_logout_use_cookie_session_without_tokens(): void
    {
        $this->postJson('/api/v1/auth/login', ['email' => $this->user->email, 'password' => 'password'])
            ->assertOk()->assertJsonPath('data.id', $this->user->id)->assertJsonMissingPath('data.password')->assertJsonMissingPath('token');
        $this->getJson('/api/v1/auth/me')->assertOk()->assertJsonPath('data.active_organization_id', $this->organization->id);
        $this->postJson('/api/v1/auth/logout')->assertNoContent();
        $this->app['auth']->forgetGuards();
        $this->getJson('/api/v1/auth/me')->assertUnauthorized();
        $this->assertDatabaseCount('personal_access_tokens', 0);
    }

    public function test_bad_login_is_422_and_rate_limited(): void
    {
        for ($attempt = 0; $attempt < 5; $attempt++) {
            $this->postJson('/api/v1/auth/login', ['email' => $this->user->email, 'password' => 'wrong'])->assertUnprocessable()->assertJsonValidationErrors('email');
        }
        $this->postJson('/api/v1/auth/login', ['email' => $this->user->email, 'password' => 'wrong'])->assertStatus(429);
        $this->assertGuest();
    }

    public function test_csrf_is_enforced_when_unit_test_bypass_is_disabled(): void
    {
        $this->app['env'] = 'local';
        $this->postJson('/api/v1/auth/login', ['email' => $this->user->email, 'password' => 'password'])->assertStatus(419);
    }

    public function test_unknown_bearer_token_is_unauthorized(): void
    {
        $this->withToken('invalid-token')->getJson('/api/v1/auth/me')->assertUnauthorized();
    }

    public function test_product_crud(): void
    {
        $this->actingAs($this->user);
        $created = $this->postJson('/api/v1/products', $this->productPayload())->assertCreated()
            ->assertJsonPath('data.price_minor', 199999)->assertJsonPath('data.organization_id', $this->organization->id);
        $id = $created->json('data.id');
        $this->getJson('/api/v1/products/'.$id)->assertOk()->assertJsonPath('data.sku', 'SKU-NEW');
        $this->patchJson('/api/v1/products/'.$id, ['name' => 'Изменено', 'price_minor' => 12345])->assertOk()->assertJsonPath('data.price_minor', 12345);
        $this->getJson('/api/v1/products')->assertOk()->assertJsonPath('meta.total', 1);
        $this->deleteJson('/api/v1/products/'.$id)->assertNoContent();
        $this->assertDatabaseMissing('products', ['id' => $id]);
    }

    public function test_marketplace_account_crud(): void
    {
        $this->actingAs($this->user);
        $id = $this->postJson('/api/v1/marketplace-accounts', ['name' => 'Демо Ozon', 'marketplace' => 'ozon', 'status' => 'demo', 'is_demo' => true])
            ->assertCreated()->json('data.id');
        $this->getJson('/api/v1/marketplace-accounts/'.$id)->assertOk()->assertJsonPath('data.listings_count', 0);
        $this->patchJson('/api/v1/marketplace-accounts/'.$id, ['name' => 'Новое имя'])->assertOk()->assertJsonPath('data.name', 'Новое имя');
        $this->getJson('/api/v1/marketplace-accounts?marketplace=ozon&is_demo=1')->assertOk()->assertJsonPath('meta.total', 1);
        $this->deleteJson('/api/v1/marketplace-accounts/'.$id)->assertNoContent();
    }

    public function test_listing_crud_stocks_and_deletion_dependencies(): void
    {
        $product = $this->product();
        $account = $this->account();
        $this->actingAs($this->user);
        $id = $this->postJson('/api/v1/marketplace-listings', $this->listingPayload($product, $account))->assertCreated()->json('data.id');
        $this->getJson('/api/v1/marketplace-listings/'.$id)->assertOk()->assertJsonPath('data.product.sku', $product->sku);
        $this->getJson('/api/v1/stocks?stock=low')->assertOk()->assertJsonPath('meta.total', 1)->assertJsonPath('data.0.stock_quantity', 3);
        $this->patchJson('/api/v1/marketplace-listings/'.$id, ['stock_quantity' => 0, 'price_minor' => 22201])->assertOk()->assertJsonPath('data.price_minor', 22201);
        $this->getJson('/api/v1/stocks?stock=zero')->assertOk()->assertJsonPath('meta.total', 1);
        $this->getJson('/api/v1/products/'.$product->id)->assertOk()->assertJsonPath('data.listings_count', 1)->assertJsonStructure(['data' => ['listings_url']]);
        $this->getJson('/api/v1/marketplace-listings?product_id='.$product->id)->assertOk()->assertJsonPath('meta.total', 1);
        $this->deleteJson('/api/v1/products/'.$product->id)->assertStatus(409);
        $this->deleteJson('/api/v1/marketplace-accounts/'.$account->id)->assertStatus(409);
        $this->deleteJson('/api/v1/marketplace-listings/'.$id)->assertNoContent();
        $this->deleteJson('/api/v1/products/'.$product->id)->assertNoContent();
        $this->deleteJson('/api/v1/marketplace-accounts/'.$account->id)->assertNoContent();
    }

    public function test_negative_and_fractional_prices_and_stock_are_rejected(): void
    {
        $product = $this->product();
        $account = $this->account();
        $this->actingAs($this->user);
        foreach ([-1, 1.5] as $price) {
            $this->postJson('/api/v1/products', $this->productPayload(['price_minor' => $price]))->assertUnprocessable()->assertJsonValidationErrors('price_minor');
        }
        $this->postJson('/api/v1/marketplace-listings', $this->listingPayload($product, $account, ['stock_quantity' => -1, 'price_minor' => -5]))
            ->assertUnprocessable()->assertJsonValidationErrors(['stock_quantity', 'price_minor']);
    }

    public function test_sku_is_unique_inside_organization_only(): void
    {
        $this->product(['sku' => 'SAME']);
        $other = Organization::factory()->create();
        $this->product(['sku' => 'FOREIGN'], $other);
        $this->actingAs($this->user);
        $this->postJson('/api/v1/products', $this->productPayload(['sku' => 'SAME']))->assertUnprocessable()->assertJsonValidationErrors('sku');
        $this->postJson('/api/v1/products', $this->productPayload(['sku' => 'FOREIGN']))->assertCreated();
    }

    public function test_foreign_ids_and_mass_assignment_are_blocked(): void
    {
        $other = Organization::factory()->create();
        $foreignProduct = $this->product([], $other);
        $foreignAccount = $this->account($other);
        $foreignListing = MarketplaceListing::factory()->create(['organization_id' => $other->id, 'product_id' => $foreignProduct->id, 'marketplace_account_id' => $foreignAccount->id]);
        $this->actingAs($this->user);
        foreach (['products' => $foreignProduct, 'marketplace-accounts' => $foreignAccount, 'marketplace-listings' => $foreignListing] as $route => $record) {
            $this->getJson('/api/v1/'.$route.'/'.$record->id)->assertNotFound();
            $this->patchJson('/api/v1/'.$route.'/'.$record->id, [])->assertNotFound();
            $this->deleteJson('/api/v1/'.$route.'/'.$record->id)->assertNotFound();
            $this->getJson('/api/v1/'.$route)->assertOk()->assertJsonPath('meta.total', 0);
        }
        $this->getJson('/api/v1/stocks')->assertJsonPath('meta.total', 0);
        $this->postJson('/api/v1/products', $this->productPayload(['organization_id' => $other->id]))->assertUnprocessable()->assertJsonValidationErrors('organization_id');
        $this->assertFalse($this->user->fresh()->is_admin);
    }

    public function test_related_records_must_share_current_organization(): void
    {
        $product = $this->product();
        $foreign = $this->account(Organization::factory()->create());
        $this->actingAs($this->user);
        $this->postJson('/api/v1/marketplace-listings', $this->listingPayload($product, $foreign))
            ->assertUnprocessable()->assertJsonValidationErrors('marketplace_account_id');
    }

    public function test_duplicate_listing_and_external_id_are_rejected(): void
    {
        $product = $this->product();
        $second = $this->product();
        $account = $this->account();
        $this->actingAs($this->user);
        $this->postJson('/api/v1/marketplace-listings', $this->listingPayload($product, $account))->assertCreated();
        $this->postJson('/api/v1/marketplace-listings', $this->listingPayload($product, $account))->assertUnprocessable();
        $this->postJson('/api/v1/marketplace-listings', $this->listingPayload($second, $account))->assertUnprocessable()->assertJsonValidationErrors('external_id');
    }

    public function test_organizations_and_selection_validate_membership(): void
    {
        $other = Organization::factory()->create();
        $second = Organization::factory()->create();
        $this->user->organizations()->attach($second);
        $secondProduct = $this->product([], $second);
        $this->actingAs($this->user);
        $this->getJson('/api/v1/organizations')->assertOk()->assertJsonPath('meta.total', 2);
        $this->patchJson('/api/v1/organizations/active', ['organization_id' => $other->id])->assertNotFound();
        $this->patchJson('/api/v1/organizations/active', ['organization_id' => $second->id])->assertOk()->assertJsonPath('data.id', $second->id);
        $this->getJson('/api/v1/products')->assertJsonPath('data.0.id', $secondProduct->id);
    }

    public function test_no_membership_prevents_business_creation(): void
    {
        $this->user->organizations()->detach();
        $this->actingAs($this->user);
        $this->postJson('/api/v1/products', $this->productPayload())->assertForbidden();
        $this->getJson('/api/v1/products')->assertOk()->assertJsonPath('meta.total', 0);
    }

    public function test_pagination_search_sort_filters_and_limits(): void
    {
        for ($i = 0; $i < 6; $i++) {
            $this->product(['sku' => 'SEARCH-'.$i, 'name' => 'Лампа '.$i, 'is_active' => $i < 4, 'base_price_minor' => $i * 100]);
        }
        $this->actingAs($this->user);
        $this->getJson('/api/v1/products?search=лампа&is_active=1&per_page=2&page=2&sort=price_minor&direction=desc')
            ->assertOk()->assertJsonPath('meta.total', 4)->assertJsonPath('meta.current_page', 2)->assertJsonCount(2, 'data')->assertJsonPath('data.0.price_minor', 100)->assertJsonStructure(['data', 'links', 'meta']);
        $this->getJson('/api/v1/products?per_page=101')->assertUnprocessable();
        $this->getJson('/api/v1/products?sort=organization_id')->assertUnprocessable();
        $this->getJson('/api/v1/marketplace-accounts?sort=sku')->assertUnprocessable();
    }

    public function test_listing_eager_loading_has_a_bounded_query_count(): void
    {
        $account = $this->account();
        for ($i = 0; $i < 12; $i++) {
            $product = $this->product();
            MarketplaceListing::factory()->create(['organization_id' => $this->organization->id, 'product_id' => $product->id, 'marketplace_account_id' => $account->id]);
        }
        $this->actingAs($this->user);
        DB::enableQueryLog();
        $this->getJson('/api/v1/stocks')->assertOk()->assertJsonCount(12, 'data');
        $count = count(DB::getQueryLog());
        DB::disableQueryLog();
        $this->assertLessThan(20, $count, 'Possible N+1 regression: '.$count.' queries');
    }

    public function test_postgresql_constraints_and_types_are_active(): void
    {
        $this->assertSame('pgsql', DB::getDriverName());
        $product = $this->product();
        $foreign = $this->account(Organization::factory()->create());
        $invalid = [
            fn () => DB::table('products')->insert(['organization_id' => $this->organization->id, 'sku' => 'BAD', 'name' => 'Bad', 'base_price_minor' => -1, 'currency' => 'RUB', 'is_active' => true]),
            fn () => DB::table('marketplace_listings')->insert(['organization_id' => $this->organization->id, 'product_id' => $product->id, 'marketplace_account_id' => $foreign->id, 'price_minor' => 0, 'currency' => 'RUB', 'stock_quantity' => 0, 'status' => 'active']),
        ];
        foreach ($invalid as $operation) {
            try {
                DB::transaction($operation);
                $this->fail('Database should reject invalid data');
            } catch (QueryException $exception) {
                $this->assertContains((string) $exception->getCode(), ['23514', '23503']);
            }
        }
    }

    public function test_demo_seed_is_repeatable_and_preserves_changes(): void
    {
        $this->seed(DemoDataSeeder::class);
        $demo = Organization::where('slug', 'markethub-demo')->firstOrFail();
        $product = Product::withoutGlobalScopes()->where('organization_id', $demo->id)->firstOrFail();
        $product->update(['name' => 'Пользовательское изменение']);
        $this->seed(DemoDataSeeder::class);
        $this->assertSame(50, Product::withoutGlobalScopes()->where('organization_id', $demo->id)->count());
        $this->assertSame(2, MarketplaceAccount::withoutGlobalScopes()->where('organization_id', $demo->id)->count());
        $this->assertSame(100, MarketplaceListing::withoutGlobalScopes()->where('organization_id', $demo->id)->count());
        $this->assertSame('Пользовательское изменение', $product->fresh()->name);
    }

    public function test_removed_server_ui_is_not_exposed_and_registration_is_absent(): void
    {
        foreach (['/admin', '/admin/login', '/livewire/update', '/register', '/api/v1/register'] as $path) {
            $this->get($path)->assertNotFound();
        }
        $this->get('/')->assertOk()->assertJsonPath('api_version', 'v1');
    }
}
