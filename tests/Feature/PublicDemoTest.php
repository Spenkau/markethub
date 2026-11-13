<?php

namespace Tests\Feature;

use App\Models\MarketplaceAccount;
use App\Models\Organization;
use App\Models\Product;
use App\Models\User;
use App\Services\PublicDemo;
use App\Services\SyntheticDemoFixtures;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

class PublicDemoTest extends TestCase
{
    use RefreshDatabase;

    private function configureDemo(): void
    {
        config(['demo.enabled' => true, 'demo.database' => DB::connection()->getDatabaseName()]);
        Http::preventStrayRequests();
    }

    public function test_bootstrap_is_atomic_synthetic_and_repeat_refuses_without_changing_data(): void
    {
        $this->configureDemo();
        putenv('DEMO_BOOTSTRAP_PASSWORD='.bin2hex(random_bytes(24)));
        try {
            $options = ['--confirm-environment' => 'testing', '--confirm-database' => DB::connection()->getDatabaseName()];
            $this->artisan('markethub:demo-bootstrap', $options)->assertExitCode(0);
            $this->assertDatabaseCount('marketplace_credentials', 0);
            $this->assertDatabaseCount('users', 1);
            $this->assertDatabaseHas('users', ['is_demo_access' => true, 'is_admin' => false]);
            $this->assertDatabaseCount('purchase_orders', 2);
            $this->assertDatabaseHas('purchase_orders', ['status' => 'partially_received']);
            $this->assertDatabaseHas('purchase_orders', ['status' => 'received']);
            $this->assertDatabaseCount('marketplace_products', 2);
            $this->assertDatabaseCount('marketplace_locations', 2);
            $this->assertDatabaseCount('marketplace_inventory_levels', 8);
            $this->assertSame(0, DB::table('marketplace_accounts')->whereNotNull('shop_domain')->count());
            $this->assertNotNull(app(PublicDemo::class)->user());
            $this->actingAs(User::first());
            $stockRows = DB::table('warehouse_stocks')->count();
            $catalog = collect($this->getJson('/api/v1/catalog')->assertOk()->json('data'))->keyBy('sku');
            $this->assertSame(8, $catalog['SYNTHETIC-1']['physical_quantity']);
            $this->assertSame(0, $catalog['SYNTHETIC-3']['physical_quantity']);
            $this->assertNull($catalog['SYNTHETIC-4']['physical_quantity']);
            $this->getJson('/api/v1/dashboard/overview?include_demo=1')->assertOk()
                ->assertJsonPath('data.physical.no_data_skus', 2)->assertJsonPath('data.physical.zero_skus', 1);
            $this->assertSame($stockRows, DB::table('warehouse_stocks')->count());
            $password = User::first()->getRawOriginal('password');
            $this->artisan('markethub:demo-bootstrap', $options)->assertExitCode(1);
            $this->assertSame($password, User::first()->getRawOriginal('password'));
            $this->assertDatabaseCount('products', 5);
            Http::assertNothingSent();
        } finally {
            putenv('DEMO_BOOTSTRAP_PASSWORD');
        }
    }

    public function test_bootstrap_requires_exact_database_environment_and_empty_database(): void
    {
        $this->artisan('markethub:demo-bootstrap')->assertExitCode(1);
        $this->configureDemo();
        $this->artisan('markethub:demo-bootstrap', ['--confirm-environment' => 'production', '--confirm-database' => DB::connection()->getDatabaseName()])->assertExitCode(1);
        config(['demo.database' => 'unrelated_demo']);
        $this->artisan('markethub:demo-bootstrap', ['--confirm-environment' => 'testing', '--confirm-database' => DB::connection()->getDatabaseName()])->assertExitCode(1);
        config(['demo.database' => DB::connection()->getDatabaseName()]);
        User::factory()->create();
        $this->artisan('markethub:demo-bootstrap', ['--confirm-environment' => 'testing', '--confirm-database' => DB::connection()->getDatabaseName()])->assertExitCode(1);
        $this->assertDatabaseCount('products', 0);
    }

    public function test_every_business_write_route_is_denied_before_binding_or_external_operations(): void
    {
        $this->configureDemo();
        app(SyntheticDemoFixtures::class)->create('demo@example.invalid', bin2hex(random_bytes(24)));
        $user = User::first();
        $this->actingAs($user);
        $before = DB::table('marketplace_listings')->count();
        foreach (Route::getRoutes() as $route) {
            if ($route->uri() === 'api/v1/auth/logout') {
                continue;
            }
            if (! str_starts_with($route->uri(), 'api/v1/') || ! in_array('auth:sanctum', $route->gatherMiddleware(), true)) {
                continue;
            }
            foreach (array_intersect($route->methods(), ['POST', 'PATCH', 'PUT', 'DELETE']) as $method) {
                $uri = preg_replace('/\{[^}]+\}/', '999999', $route->uri());
                $this->json($method, '/'.$uri, [])->assertForbidden();
            }
        }
        $this->getJson('/api/v1/catalog')->assertOk();
        $this->getJson('/api/v1/dashboard/overview?include_demo=1')->assertOk();
        $this->getJson('/api/v1/products/export')->assertOk();
        $this->assertSame($before, DB::table('marketplace_listings')->count());
        Http::assertNothingSent();
    }

    public function test_demo_login_is_explicit_and_rejects_real_accounts_or_credentials(): void
    {
        $this->getJson('/api/v1/auth/demo')->assertOk()->assertJson(['available' => false]);
        $this->postJson('/api/v1/auth/demo')->assertStatus(503);
        $this->configureDemo();
        app(SyntheticDemoFixtures::class)->create('demo@example.invalid', bin2hex(random_bytes(24)));
        $this->postJson('/api/v1/auth/demo')->assertOk()->assertJsonPath('data.is_demo_access', true);
        $account = MarketplaceAccount::withoutGlobalScopes()->first();
        $account->forceFill(['is_demo' => false])->save();
        $this->postJson('/api/v1/auth/demo')->assertStatus(503);
    }

    public function test_bootstrap_refuses_credentials(): void
    {
        $this->configureDemo();
        $org = Organization::factory()->create();
        $account = MarketplaceAccount::factory()->create(['organization_id' => $org->id, 'marketplace' => 'shopify', 'is_demo' => false]);
        $account->forceFill(['shopify_client_id' => 'synthetic-test-id', 'shopify_client_secret' => 'synthetic-test-secret'])->save();
        $this->artisan('markethub:demo-bootstrap', ['--confirm-environment' => 'testing', '--confirm-database' => DB::connection()->getDatabaseName()])->assertExitCode(1);
        $this->assertDatabaseCount('products', 0);
        $this->assertSame('synthetic-test-secret', $account->fresh()->shopify_client_secret);
        $this->assertNull(app(PublicDemo::class)->user());
    }

    public function test_failed_fixture_creation_rolls_back_without_printing_sensitive_context(): void
    {
        $this->configureDemo();
        $secret = bin2hex(random_bytes(24));
        putenv('DEMO_BOOTSTRAP_PASSWORD='.$secret);
        $this->mock(SyntheticDemoFixtures::class)->shouldReceive('create')->once()->andReturnUsing(function () use ($secret) {
            Organization::factory()->create();
            throw new \RuntimeException($secret);
        });
        try {
            $this->artisan('markethub:demo-bootstrap', ['--confirm-environment' => 'testing', '--confirm-database' => DB::connection()->getDatabaseName()])
                ->expectsOutput('Demo bootstrap failed; the transaction was rolled back. Check schema and application configuration.')->assertExitCode(1);
            $this->assertDatabaseCount('organizations', 0);
            $this->assertDatabaseCount('users', 0);
        } finally {
            putenv('DEMO_BOOTSTRAP_PASSWORD');
        }
    }

    public function test_stock_null_zero_and_positive_are_distinct_and_ordinary_crud_remains_available(): void
    {
        $org = Organization::factory()->create();
        $user = User::factory()->create(['active_organization_id' => $org->id]);
        $user->organizations()->attach($org);
        $this->actingAs($user);
        $this->postJson('/api/v1/products', ['sku' => 'NORMAL', 'name' => 'Normal', 'price_minor' => 100, 'currency' => 'BYN', 'is_active' => true])->assertCreated();
        $this->getJson('/api/v1/catalog')->assertOk()->assertJsonPath('data.0.physical_quantity', null);
        $this->assertDatabaseCount('warehouse_stocks', 0);
        $this->assertFalse((bool) (new User)->fill(['is_demo_access' => true])->is_demo_access);
        $other = Organization::factory()->create();
        $hidden = Product::withoutEvents(fn () => Product::factory()->create(['organization_id' => $other->id]));
        $this->getJson('/api/v1/catalog/local/'.$hidden->id)->assertNotFound();
    }
}
