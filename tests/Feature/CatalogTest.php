<?php

namespace Tests\Feature;

use App\Filament\Resources\MarketplaceAccounts\Pages\CreateMarketplaceAccount;
use App\Filament\Resources\MarketplaceListings\Pages\CreateMarketplaceListing;
use App\Filament\Resources\MarketplaceListings\Pages\EditMarketplaceListing;
use App\Filament\Resources\MarketplaceListings\Pages\ListMarketplaceListings;
use App\Filament\Resources\Products\Pages\CreateProduct;
use App\Filament\Resources\Products\Pages\EditProduct;
use App\Filament\Resources\Products\Pages\ListProducts;
use App\Models\MarketplaceAccount;
use App\Models\MarketplaceListing;
use App\Models\Organization;
use App\Models\Product;
use App\Models\User;
use App\Support\Money;
use App\Support\OrganizationContext;
use Database\Seeders\DemoDataSeeder;
use Filament\Facades\Filament;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class CatalogTest extends TestCase
{
    use RefreshDatabase;

    private Organization $organization;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->organization = Organization::factory()->create();
        $this->admin = User::factory()->create(['is_admin' => true]);
        $this->admin->organizations()->attach($this->organization);
        Filament::setCurrentPanel(Filament::getPanel('admin'));
    }

    private function product(?Organization $organization = null, array $data = []): Product
    {
        return Product::factory()->create(['organization_id' => ($organization ?? $this->organization)->id, ...$data]);
    }

    private function account(?Organization $organization = null): MarketplaceAccount
    {
        return MarketplaceAccount::factory()->create(['organization_id' => ($organization ?? $this->organization)->id]);
    }

    private function listing(Product $product, MarketplaceAccount $account, array $data = []): MarketplaceListing
    {
        return MarketplaceListing::factory()->create([
            'organization_id' => $product->organization_id, 'product_id' => $product->id,
            'marketplace_account_id' => $account->id, ...$data,
        ]);
    }

    public function test_product_creation_through_filament_converts_money_exactly(): void
    {
        $this->actingAs($this->admin);
        Livewire::test(CreateProduct::class)->fillForm([
            'sku' => 'NEW-01', 'name' => 'Новый товар', 'description' => 'Описание',
            'base_price_minor' => '1234,56', 'currency' => 'RUB', 'is_active' => true,
        ])->call('create')->assertHasNoFormErrors();

        $this->assertDatabaseHas('products', ['organization_id' => $this->organization->id, 'sku' => 'NEW-01', 'base_price_minor' => 123456]);
    }

    public function test_product_edit_does_not_multiply_existing_price(): void
    {
        $product = $this->product(data: ['base_price_minor' => 123456]);
        $this->actingAs($this->admin);
        Livewire::test(EditProduct::class, ['record' => $product->id])->assertFormSet(['base_price_minor' => '1234.56'])
            ->fillForm(['name' => 'Изменённое название'])->call('save')->assertHasNoFormErrors();
        $this->assertDatabaseHas('products', ['id' => $product->id, 'name' => 'Изменённое название', 'base_price_minor' => 123456]);
    }

    public function test_product_form_rejects_negative_price_and_missing_fields(): void
    {
        $this->actingAs($this->admin);
        Livewire::test(CreateProduct::class)->fillForm([
            'sku' => '', 'name' => '', 'base_price_minor' => '-1.00', 'currency' => null,
        ])->call('create')->assertHasFormErrors(['sku', 'name', 'base_price_minor', 'currency']);
        $this->assertDatabaseCount('products', 0);
    }

    public function test_sku_must_be_unique_in_organization_but_can_repeat_elsewhere(): void
    {
        $this->product(data: ['sku' => 'SAME']);
        $other = Organization::factory()->create();
        $this->product($other, ['sku' => 'SAME']);
        $this->actingAs($this->admin);
        Livewire::test(CreateProduct::class)->fillForm([
            'sku' => 'SAME', 'name' => 'Повтор', 'base_price_minor' => '1.00', 'currency' => 'RUB', 'is_active' => true,
        ])->call('create')->assertHasFormErrors(['sku']);
        $this->assertDatabaseCount('products', 2);
    }

    public function test_listing_creation_through_filament(): void
    {
        $product = $this->product();
        $account = $this->account();
        $this->actingAs($this->admin);
        Livewire::test(CreateMarketplaceListing::class)->fillForm([
            'product_id' => $product->id, 'marketplace_account_id' => $account->id,
            'external_id' => 'SYNTHETIC-TEST-1', 'external_sku' => 'DEMO-1',
            'price_minor' => '199.99', 'currency' => 'RUB', 'stock_quantity' => 8, 'status' => 'active',
        ])->call('create')->assertHasNoFormErrors();
        $this->assertDatabaseHas('marketplace_listings', ['organization_id' => $this->organization->id, 'product_id' => $product->id, 'price_minor' => 19999, 'stock_quantity' => 8]);
    }

    public function test_product_can_have_two_marketplaces_and_multiple_accounts_of_same_type(): void
    {
        $product = $this->product();
        $wb = $this->account();
        $ozon = $this->account();
        $ozon->update(['marketplace' => 'ozon']);
        $wb2 = $this->account();
        foreach ([$wb, $ozon, $wb2] as $account) {
            $this->listing($product, $account);
        }
        $this->actingAs($this->admin);
        $this->assertCount(3, $product->listings);
    }

    public function test_listing_form_rejects_negative_stock_and_price(): void
    {
        $product = $this->product();
        $account = $this->account();
        $this->actingAs($this->admin);
        Livewire::test(CreateMarketplaceListing::class)->fillForm([
            'product_id' => $product->id, 'marketplace_account_id' => $account->id,
            'price_minor' => '-0.01', 'currency' => 'RUB', 'stock_quantity' => -1, 'status' => 'active',
        ])->call('create')->assertHasFormErrors(['stock_quantity', 'price_minor']);
    }

    public function test_model_rejects_relations_between_organizations(): void
    {
        $product = $this->product();
        $foreignAccount = $this->account(Organization::factory()->create());
        $this->expectException(ValidationException::class);
        $this->listing($product, $foreignAccount);
    }

    public function test_form_rejects_foreign_related_ids(): void
    {
        $other = Organization::factory()->create();
        $foreignProduct = $this->product($other);
        $foreignAccount = $this->account($other);
        $this->actingAs($this->admin);
        Livewire::test(CreateMarketplaceListing::class)->fillForm([
            'product_id' => $foreignProduct->id, 'marketplace_account_id' => $foreignAccount->id,
            'price_minor' => '1.00', 'currency' => 'RUB', 'stock_quantity' => 1, 'status' => 'active',
        ])->call('create')->assertHasFormErrors(['product_id', 'marketplace_account_id']);
        $this->assertDatabaseCount('marketplace_listings', 0);
    }

    public function test_duplicate_listing_is_rejected(): void
    {
        $product = $this->product();
        $account = $this->account();
        $this->listing($product, $account);
        $this->expectException(ValidationException::class);
        $this->listing($product, $account);
    }

    public function test_external_id_is_unique_per_account_and_nullable(): void
    {
        $account = $this->account();
        $this->listing($this->product(), $account, ['external_id' => null]);
        $this->listing($this->product(), $account, ['external_id' => null]);
        $this->listing($this->product(), $account, ['external_id' => 'SYNTHETIC-SAME']);
        $this->listing($this->product(), $this->account(), ['external_id' => 'SYNTHETIC-SAME']);
        $this->expectException(ValidationException::class);
        $this->listing($this->product(), $account, ['external_id' => 'SYNTHETIC-SAME']);
    }

    public function test_foreign_records_are_hidden_and_policy_rejects_them(): void
    {
        $own = $this->product();
        $other = Organization::factory()->create();
        $foreign = $this->product($other);
        $account = $this->account($other);
        $listing = $this->listing($foreign, $account);
        $this->actingAs($this->admin);
        $this->assertNull(Product::find($foreign->id));
        $this->assertFalse(Gate::allows('view', $foreign));
        $this->assertFalse(Gate::allows('update', $foreign));
        $this->assertFalse(Gate::allows('delete', $foreign));
        foreach (["/admin/products/{$foreign->id}", "/admin/products/{$foreign->id}/edit", "/admin/marketplaces/{$account->id}", "/admin/inventory/{$listing->id}/edit"] as $url) {
            $this->get($url)->assertNotFound();
        }
        Livewire::test(ListProducts::class)->assertCanSeeTableRecords([$own])->assertCanNotSeeTableRecords([$foreign]);
    }

    public function test_search_activity_and_stock_filters(): void
    {
        $active = $this->product(data: ['name' => 'Уникальная лампа', 'is_active' => true]);
        $inactive = $this->product(data: ['is_active' => false]);
        $zero = $this->listing($active, $this->account(), ['stock_quantity' => 0]);
        $low = $this->listing($inactive, $this->account(), ['stock_quantity' => 3]);
        $this->actingAs($this->admin);
        Livewire::test(ListProducts::class)->searchTable('Уникальная лампа')->assertCanSeeTableRecords([$active])->assertCanNotSeeTableRecords([$inactive]);
        Livewire::test(ListProducts::class)->filterTable('is_active', false)->assertCanSeeTableRecords([$inactive])->assertCanNotSeeTableRecords([$active]);
        Livewire::test(ListMarketplaceListings::class)->filterTable('zero_stock')->assertCanSeeTableRecords([$zero])->assertCanNotSeeTableRecords([$low]);
        Livewire::test(ListMarketplaceListings::class)->filterTable('low_stock')->assertCanSeeTableRecords([$low])->assertCanNotSeeTableRecords([$zero]);
    }

    public function test_mass_assignment_cannot_change_organization(): void
    {
        $product = $this->product();
        $other = Organization::factory()->create();
        $this->actingAs($this->admin);
        $product->update(['organization_id' => $other->id, 'name' => 'Новое имя']);
        $this->assertSame($this->organization->id, $product->fresh()->organization_id);
    }

    public function test_active_organization_must_be_a_membership(): void
    {
        $other = Organization::factory()->create();
        $this->admin->active_organization_id = $other->id;
        $this->admin->save();
        $this->assertSame($this->organization->id, OrganizationContext::id($this->admin));
        $this->admin->organizations()->attach($other);
        $this->assertSame($other->id, OrganizationContext::id($this->admin));
    }

    public function test_no_organization_means_no_business_access(): void
    {
        $user = User::factory()->create(['is_admin' => true]);
        $this->product();
        $this->actingAs($user);
        $this->assertSame(0, Product::count());
        $this->get('/admin/products/create')->assertForbidden();
    }

    public function test_product_with_dependencies_cannot_be_deleted(): void
    {
        $product = $this->product();
        $this->listing($product, $this->account());
        $this->actingAs($this->admin);
        $this->assertFalse(Gate::allows('delete', $product));
        $this->expectException(ValidationException::class);
        $product->delete();
    }

    public function test_product_without_dependencies_can_be_deleted(): void
    {
        $product = $this->product();
        $this->actingAs($this->admin);
        $this->assertTrue(Gate::allows('delete', $product));
        $product->delete();
        $this->assertDatabaseMissing('products', ['id' => $product->id]);
    }

    public function test_database_constraints_reject_cross_organization_even_without_model_events(): void
    {
        $product = $this->product();
        $account = $this->account(Organization::factory()->create());
        $this->expectException(QueryException::class);
        DB::table('marketplace_listings')->insert([
            'organization_id' => $product->organization_id, 'product_id' => $product->id, 'marketplace_account_id' => $account->id,
            'price_minor' => 0, 'currency' => 'RUB', 'stock_quantity' => 0, 'status' => 'active',
        ]);
    }

    public function test_database_rejects_negative_money_without_model_events(): void
    {
        $this->expectException(QueryException::class);
        DB::table('products')->insert([
            'organization_id' => $this->organization->id, 'sku' => 'BAD', 'name' => 'Bad',
            'base_price_minor' => -1, 'currency' => 'RUB', 'is_active' => true,
        ]);
    }

    public function test_database_rejects_negative_stock_without_model_events(): void
    {
        $product = $this->product();
        $account = $this->account();
        $this->expectException(QueryException::class);
        DB::table('marketplace_listings')->insert([
            'organization_id' => $product->organization_id, 'product_id' => $product->id, 'marketplace_account_id' => $account->id,
            'price_minor' => 100, 'currency' => 'RUB', 'stock_quantity' => -1, 'status' => 'active',
        ]);
    }

    public function test_loaded_foreign_record_cannot_be_updated(): void
    {
        $foreign = $this->product(Organization::factory()->create());
        $this->actingAs($this->admin);
        $this->expectException(ValidationException::class);
        $foreign->update(['name' => 'Попытка подмены']);
    }

    public function test_database_prevents_deleting_a_product_with_listings(): void
    {
        $product = $this->product();
        $this->listing($product, $this->account());
        $this->expectException(QueryException::class);
        DB::table('products')->where('id', $product->id)->delete();
    }

    public function test_listing_edit_and_relationship_views_are_available(): void
    {
        $product = $this->product();
        $account = $this->account();
        $listing = $this->listing($product, $account, ['price_minor' => 12345]);
        $this->actingAs($this->admin);
        $this->get('/admin/products/'.$product->id)->assertOk()->assertSee('Размещения')->assertSee($account->name);
        $this->get('/admin/marketplaces/'.$account->id)->assertOk()->assertSee('Размещения')->assertSee($product->name);
        Livewire::test(EditMarketplaceListing::class, ['record' => $listing->id])
            ->assertFormSet(['price_minor' => '123.45'])->fillForm(['stock_quantity' => 15, 'price_minor' => '222.01'])
            ->call('save')->assertHasNoFormErrors();
        $this->assertDatabaseHas('marketplace_listings', ['id' => $listing->id, 'stock_quantity' => 15, 'price_minor' => 22201]);
    }

    public function test_marketplace_account_can_be_created_in_current_organization(): void
    {
        $this->actingAs($this->admin);
        Livewire::test(CreateMarketplaceAccount::class)
            ->fillForm(['name' => 'Тестовое подключение Ozon', 'marketplace' => 'ozon', 'status' => 'demo', 'is_demo' => true])
            ->call('create')->assertHasNoFormErrors();
        $this->assertDatabaseHas('marketplace_accounts', ['organization_id' => $this->organization->id, 'marketplace' => 'ozon']);
    }

    public function test_demo_seeder_is_repeatable_and_preserves_user_changes(): void
    {
        $existing = $this->product();
        $this->seed(DemoDataSeeder::class);
        $demo = Organization::where('slug', 'markethub-demo')->firstOrFail();
        $product = Product::withoutGlobalScopes()->where('organization_id', $demo->id)->firstOrFail();
        $product->name = 'Пользователь изменил название';
        $product->save();
        $this->seed(DemoDataSeeder::class);
        $this->assertSame(50, Product::withoutGlobalScopes()->where('organization_id', $demo->id)->count());
        $this->assertSame(2, MarketplaceAccount::withoutGlobalScopes()->where('organization_id', $demo->id)->count());
        $this->assertSame(100, MarketplaceListing::withoutGlobalScopes()->where('organization_id', $demo->id)->count());
        $this->assertSame('Пользователь изменил название', $product->fresh()->name);
        $this->assertDatabaseHas('products', ['id' => $existing->id, 'sku' => $existing->sku]);
        $this->assertTrue(Product::withoutGlobalScopes()->where('organization_id', $demo->id)->where('is_active', false)->exists());
        $this->assertTrue(MarketplaceListing::withoutGlobalScopes()->where('organization_id', $demo->id)->where('stock_quantity', 0)->exists());
        $this->assertFalse(MarketplaceListing::withoutGlobalScopes()->where('organization_id', $demo->id)->where('external_id', 'not like', 'SYNTHETIC-%')->exists());
    }

    public function test_demo_attachment_requires_confirmation_and_preserves_other_memberships(): void
    {
        $this->seed(DemoDataSeeder::class);
        $this->app['env'] = 'local';
        $question = 'Привязать демокомпанию к '.$this->admin->email.' и сделать её активной?';
        $this->artisan('markethub:demo-attach', ['email' => $this->admin->email])->expectsConfirmation($question, 'no')->assertFailed();
        $this->assertCount(1, $this->admin->organizations);
        $this->artisan('markethub:demo-attach', ['email' => $this->admin->email])->expectsConfirmation($question, 'yes')->assertSuccessful();
        $this->assertSame(2, $this->admin->organizations()->count());
        $this->assertSame(Organization::where('slug', 'markethub-demo')->value('id'), OrganizationContext::id($this->admin->fresh()));
    }

    public static function exactPrices(): array
    {
        return [['0.01', 1], ['10,10', 1010], ['9999999999.99', 999999999999], ['123', 12300]];
    }

    #[DataProvider('exactPrices')]
    public function test_money_uses_exact_integer_arithmetic(string $major, int $minor): void
    {
        $this->assertSame($minor, Money::parse($major));
        $this->assertSame($minor, Money::parse(Money::decimal($minor)));
    }
}
