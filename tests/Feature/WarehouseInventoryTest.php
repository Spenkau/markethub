<?php

namespace Tests\Feature;

use App\Models\MarketplaceAccount;
use App\Models\MarketplaceListing;
use App\Models\Organization;
use App\Models\Product;
use App\Models\StockMovement;
use App\Models\User;
use App\Models\Warehouse;
use App\Models\WarehouseStock;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use Tests\TestCase;

class WarehouseInventoryTest extends TestCase
{
    use RefreshDatabase;

    private Organization $organization;

    private User $user;

    private Product $product;

    private Warehouse $warehouse;

    private Warehouse $destination;

    protected function setUp(): void
    {
        parent::setUp();
        $this->organization = Organization::factory()->create();
        $this->user = User::factory()->create();
        $this->user->organizations()->attach($this->organization);
        $this->product = Product::factory()->create(['organization_id' => $this->organization->id]);
        $this->warehouse = $this->warehouse('MAIN');
        $this->destination = $this->warehouse('OTHER');
        $this->actingAs($this->user);
    }

    private function warehouse(string $code, ?Organization $organization = null): Warehouse
    {
        $record = new Warehouse(['name' => 'Склад '.$code, 'code' => $code, 'type' => 'own', 'is_active' => true]);
        $record->organization_id = ($organization ?? $this->organization)->id;
        $record->save();

        return $record;
    }

    private function payload(string $type = 'receipt', int $quantity = 10, array $overrides = []): array
    {
        return [...['operation_id' => (string) Str::uuid(), 'type' => $type, 'warehouse_id' => $this->warehouse->id, 'product_id' => $this->product->id, 'quantity' => $quantity, 'comment' => 'Проверка'], ...$overrides];
    }

    private function operation(string $type = 'receipt', int $quantity = 10, array $overrides = [])
    {
        return $this->postJson('/api/v1/stock-operations', $this->payload($type, $quantity, $overrides));
    }

    public function test_warehouse_crud_filters_and_scoped_unique_codes(): void
    {
        $id = $this->postJson('/api/v1/warehouses', ['name' => 'Новый склад', 'code' => ' new ', 'type' => 'marketplace', 'is_active' => true])
            ->assertCreated()->assertJsonPath('data.code', 'NEW')->json('data.id');
        $this->postJson('/api/v1/warehouses', ['name' => 'Дубликат', 'code' => 'NEW', 'type' => 'own', 'is_active' => true])->assertUnprocessable();
        $this->patchJson('/api/v1/warehouses/'.$id, ['name' => 'Архив', 'is_active' => false])->assertOk()->assertJsonPath('data.is_active', false);
        $this->getJson('/api/v1/warehouses?search=архив&is_active=0')->assertOk()->assertJsonPath('meta.total', 1);
        $this->getJson('/api/v1/warehouses/'.$id)->assertOk();
        $this->deleteJson('/api/v1/warehouses/'.$id)->assertMethodNotAllowed();
    }

    public function test_receipt_write_off_inventory_and_readable_immutable_history(): void
    {
        $this->operation()->assertCreated()->assertJsonPath('data.movements.0.quantity_before', 0)->assertJsonPath('data.movements.0.quantity_after', 10);
        $this->operation('write_off', 3)->assertCreated()->assertJsonPath('data.movements.0.quantity_delta', -3);
        $this->operation('inventory', 2)->assertCreated()->assertJsonPath('data.movements.0.quantity_delta', -5);
        $this->operation('inventory', 2)->assertCreated()->assertJsonPath('data.movements.0.quantity_delta', 0);
        $this->operation('inventory', 0)->assertCreated();
        $this->getJson('/api/v1/warehouse-stocks?stock=zero&search='.urlencode($this->product->sku))->assertOk()->assertJsonPath('meta.total', 1)->assertJsonPath('data.0.quantity', 0);
        $this->getJson('/api/v1/stock-movements?warehouse_id='.$this->warehouse->id.'&type=write_off')->assertOk()->assertJsonPath('meta.total', 1)->assertJsonPath('data.0.user.id', $this->user->id)->assertJsonMissingPath('data.0.request_hash');
        $this->getJson('/api/v1/stock-movements?date_to='.now()->toDateString().'&per_page=2')->assertOk()->assertJsonCount(2, 'data')->assertJsonPath('meta.total', 5);
        $this->getJson('/api/v1/warehouse-stocks/summary')->assertOk()->assertJsonPath('data.units', 0)->assertJsonPath('data.zero_positions', 1)->assertJsonPath('data.active_warehouses', 2);
        $id = StockMovement::first()->id;
        $this->patchJson('/api/v1/stock-movements/'.$id, ['quantity_delta' => 99])->assertNotFound();
        $this->assertDatabaseCount('stock_movements', 5);
        try {
            DB::transaction(fn () => DB::table('stock_movements')->where('id', $id)->update(['comment' => 'changed']));
            $this->fail('History update must be rejected');
        } catch (QueryException $exception) {
            $this->assertSame('23514', $exception->getCode());
        }
        try {
            DB::transaction(fn () => DB::table('stock_movements')->where('id', $id)->delete());
            $this->fail('History delete must be rejected');
        } catch (QueryException $exception) {
            $this->assertSame('23514', $exception->getCode());
        }
    }

    public function test_negative_stock_is_rejected_without_creating_empty_positions(): void
    {
        $this->operation('write_off', 1)->assertUnprocessable()->assertJsonValidationErrors('quantity');
        $this->assertDatabaseCount('warehouse_stocks', 0);
        $this->assertDatabaseCount('stock_movements', 0);
        $this->operation('receipt', 5)->assertCreated();
        $this->operation('write_off', 6)->assertUnprocessable();
        $this->assertSame(5, WarehouseStock::first()->quantity);
        $this->assertDatabaseCount('stock_movements', 1);
    }

    public function test_transfer_is_atomic_including_failure_on_destination_overflow(): void
    {
        $this->operation()->assertCreated();
        $transfer = $this->operation('transfer', 4, ['destination_warehouse_id' => $this->destination->id])->assertCreated();
        $this->assertSame($transfer->json('data.movements.0.operation_id'), $transfer->json('data.movements.1.operation_id'));
        $this->assertSame([-4, 4], array_column($transfer->json('data.movements'), 'quantity_delta'));
        $this->assertSame(6, WarehouseStock::where('warehouse_id', $this->warehouse->id)->value('quantity'));
        $this->operation('transfer', 7, ['destination_warehouse_id' => $this->destination->id])->assertUnprocessable();
        $this->operation('inventory', 2147483647, ['warehouse_id' => $this->destination->id])->assertCreated();
        $this->operation('transfer', 1, ['destination_warehouse_id' => $this->destination->id])->assertUnprocessable();
        $this->assertSame(6, WarehouseStock::where('warehouse_id', $this->warehouse->id)->value('quantity'));
        $this->assertDatabaseCount('stock_movements', 4);
        $this->assertSame(2147483647, WarehouseStock::where('warehouse_id', $this->destination->id)->value('quantity'));
    }

    public function test_retries_are_idempotent_and_conflicting_reuse_is_409(): void
    {
        $payload = $this->payload();
        $first = $this->postJson('/api/v1/stock-operations', $payload)->assertCreated()->assertJsonPath('meta.replayed', false);
        $this->postJson('/api/v1/stock-operations', $payload)->assertOk()->assertJsonPath('meta.replayed', true)->assertJsonPath('data.movements.0.id', $first->json('data.movements.0.id'));
        $this->postJson('/api/v1/stock-operations', [...$payload, 'quantity' => 20])->assertConflict();
        $this->assertSame(10, WarehouseStock::first()->quantity);
        $this->assertDatabaseCount('stock_movements', 1);
        $transfer = $this->payload('transfer', 5, ['destination_warehouse_id' => $this->destination->id]);
        $this->postJson('/api/v1/stock-operations', $transfer)->assertCreated();
        $this->postJson('/api/v1/stock-operations', $transfer)->assertOk();
        $this->assertDatabaseCount('stock_movements', 3);
    }

    public function test_tenant_isolation_and_policies_for_all_inventory_endpoints(): void
    {
        $this->operation()->assertCreated();
        $this->app['auth']->forgetGuards();
        $other = Organization::factory()->create();
        $otherWarehouse = $this->warehouse('MAIN', $other);
        $otherProduct = Product::factory()->create(['organization_id' => $other->id]);
        $this->actingAs($this->user);
        $this->getJson('/api/v1/warehouses/'.$otherWarehouse->id)->assertNotFound();
        $this->patchJson('/api/v1/warehouses/'.$otherWarehouse->id, ['is_active' => false])->assertNotFound();
        $this->operation('receipt', 1, ['warehouse_id' => $otherWarehouse->id])->assertUnprocessable();
        $this->operation('receipt', 1, ['product_id' => $otherProduct->id])->assertUnprocessable();
        $this->operation('transfer', 1, ['destination_warehouse_id' => $otherWarehouse->id])->assertUnprocessable();
        $this->getJson('/api/v1/warehouse-stocks?warehouse_id='.$otherWarehouse->id)->assertJsonPath('meta.total', 0);
        $this->user->organizations()->attach($other);
        $this->patchJson('/api/v1/organizations/active', ['organization_id' => $other->id])->assertOk();
        $this->getJson('/api/v1/warehouse-stocks')->assertJsonPath('meta.total', 0);
        $this->getJson('/api/v1/stock-movements')->assertJsonPath('meta.total', 0);
        $this->getJson('/api/v1/warehouse-stocks/summary')->assertJsonPath('data.units', 0)->assertJsonPath('data.warehouses', 1);
        Gate::before(fn () => false);
        $this->getJson('/api/v1/warehouses')->assertForbidden();
        $this->operation()->assertForbidden();
    }

    public function test_no_membership_or_anonymous_access(): void
    {
        $this->user->organizations()->detach();
        foreach (['warehouses', 'warehouse-stocks', 'warehouse-stocks/summary', 'stock-movements'] as $path) {
            $this->getJson('/api/v1/'.$path)->assertForbidden();
        }
        $this->operation()->assertForbidden();
        $this->app['auth']->forgetGuards();
        foreach (['warehouses', 'warehouse-stocks', 'stock-movements'] as $path) {
            $this->getJson('/api/v1/'.$path)->assertUnauthorized();
        }
    }

    public function test_validation_inactive_warehouses_and_quantity_limits(): void
    {
        foreach ([-1, 1.5, 2147483648, 'abc', null] as $quantity) {
            $this->postJson('/api/v1/stock-operations', [...$this->payload(), 'quantity' => $quantity])->assertUnprocessable();
        }
        $this->operation('receipt', 0)->assertUnprocessable();
        $this->operation('transfer', 1, ['destination_warehouse_id' => $this->warehouse->id])->assertUnprocessable();
        $this->operation('transfer')->assertUnprocessable();
        $this->operation('wrong')->assertUnprocessable();
        $this->operation('receipt', 1, ['organization_id' => $this->organization->id])->assertUnprocessable();
        $this->operation('receipt', 1, ['operation_id' => 'bad'])->assertUnprocessable();
        $this->operation('receipt', 1, ['comment' => str_repeat('a', 2001)])->assertUnprocessable();
        $this->warehouse->update(['is_active' => false]);
        $this->operation()->assertUnprocessable();
        $this->assertDatabaseCount('stock_movements', 0);
        $this->getJson('/api/v1/warehouse-stocks?per_page=101')->assertUnprocessable();
        $this->getJson('/api/v1/stock-movements?date_from=2026-11-11&date_to=2026-01-01')->assertUnprocessable();
        $this->getJson('/api/v1/warehouses?sort=quantity')->assertUnprocessable();
    }

    public function test_stock_constraints_and_product_deletion_preserve_audit_trail(): void
    {
        $this->operation()->assertCreated();
        $this->deleteJson('/api/v1/products/'.$this->product->id)->assertConflict();
        $row = WarehouseStock::first();
        foreach ([['quantity' => -1], ['warehouse_id' => $this->destination->id, 'product_id' => $this->product->id, 'organization_id' => Organization::factory()->create()->id]] as $attributes) {
            try {
                DB::transaction(fn () => DB::table('warehouse_stocks')->where('id', $row->id)->update($attributes));
                $this->fail('Invalid stock write must be rejected by PostgreSQL.');
            } catch (QueryException $exception) {
                $this->assertContains($exception->getCode(), ['23503', '23514']);
            }
        }
        try {
            DB::transaction(fn () => DB::table('warehouse_stocks')->insert(['organization_id' => $this->organization->id, 'warehouse_id' => $this->warehouse->id, 'product_id' => $this->product->id, 'quantity' => 0, 'created_at' => now(), 'updated_at' => now()]));
            $this->fail('A second stock row for the same warehouse/product must be rejected.');
        } catch (QueryException $exception) {
            $this->assertSame('23505', $exception->getCode());
        }
    }

    public function test_marketplace_quantities_are_separate_from_physical_stock(): void
    {
        $account = MarketplaceAccount::factory()->create(['organization_id' => $this->organization->id]);
        MarketplaceListing::factory()->create(['organization_id' => $this->organization->id, 'marketplace_account_id' => $account->id, 'product_id' => $this->product->id, 'stock_quantity' => 77]);
        $this->operation('receipt', 10)->assertCreated();
        $this->getJson('/api/v1/stocks')->assertOk()->assertJsonPath('data.0.stock_quantity', 77);
        $this->getJson('/api/v1/warehouse-stocks')->assertOk()->assertJsonPath('data.0.quantity', 10);
        $this->getJson('/api/v1/warehouse-stocks/summary?warehouse_id='.$this->destination->id)->assertOk()->assertJsonPath('data.units', 0)->assertJsonPath('data.positions', 0);
    }

    public function test_movement_time_and_date_filters_are_utc_independent_of_database_timezone(): void
    {
        DB::statement("SET LOCAL TIME ZONE 'Europe/Minsk'");
        $this->travelTo(Carbon::parse('2026-11-11T23:30:00+00:00'));
        $this->operation()->assertCreated();
        $this->getJson('/api/v1/stock-movements?date_from=2026-11-11&date_to=2026-11-11')->assertOk()->assertJsonPath('meta.total', 1)->assertJsonPath('data.0.occurred_at', '2026-11-11T23:30:00.000000Z');
        $this->getJson('/api/v1/stock-movements?date_from=2026-11-12')->assertOk()->assertJsonPath('meta.total', 0);
        $this->getJson('/api/v1/warehouses?type=receipt')->assertUnprocessable();
        $this->getJson('/api/v1/stock-movements?type=own')->assertUnprocessable();
    }
}
