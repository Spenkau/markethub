<?php

namespace Tests\Feature;

use App\Models\Organization;
use App\Models\Product;
use App\Models\PurchaseOrder;
use App\Models\StockMovement;
use App\Models\Supplier;
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

class ProcurementTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    private Organization $organization;

    private Supplier $supplier;

    private Warehouse $warehouse;

    private Product $product;

    private Product $second;

    protected function setUp(): void
    {
        parent::setUp();
        $this->organization = Organization::factory()->create();
        $this->user = User::factory()->create();
        $this->user->organizations()->attach($this->organization);
        $this->supplier = Supplier::forceCreate(['organization_id' => $this->organization->id, 'name' => 'Поставщик']);
        $this->warehouse = new Warehouse(['name' => 'Основной', 'code' => 'MAIN', 'type' => 'own', 'is_active' => true]);
        $this->warehouse->organization_id = $this->organization->id;
        $this->warehouse->save();
        $this->product = Product::factory()->create(['organization_id' => $this->organization->id]);
        $this->second = Product::factory()->create(['organization_id' => $this->organization->id]);
        $this->actingAs($this->user);
    }

    private function payload(array $extra = []): array
    {
        return ['request_id' => (string) Str::uuid(), 'supplier_id' => $this->supplier->id, 'warehouse_id' => $this->warehouse->id, 'currency' => 'RUB', 'items' => [['product_id' => $this->product->id, 'quantity' => 10, 'unit_price' => '12.34']], ...$extra];
    }

    private function order(bool $ordered = true, array $extra = []): array
    {
        $data = $this->postJson('/api/v1/purchase-orders', $this->payload($extra))->assertCreated()->json('data');
        if ($ordered) {
            $data = $this->postJson('/api/v1/purchase-orders/'.$data['id'].'/order')->assertOk()->json('data');
        }

        return $data;
    }

    private function receipt(array $order, int $quantity, array $extra = []): array
    {
        return $this->postJson('/api/v1/goods-receipts', ['request_id' => (string) Str::uuid(), 'purchase_order_id' => $order['id'], 'items' => [['purchase_order_item_id' => $order['items'][0]['id'], 'quantity' => $quantity]], ...$extra])->assertCreated()->json('data');
    }

    public function test_supplier_crud_search_filters_and_deletion_of_used_supplier(): void
    {
        $id = $this->postJson('/api/v1/suppliers', ['name' => 'Новый', 'contact_name' => 'Иван', 'email' => 'vendor@example.test', 'phone' => '+123', 'inn' => '1234567890', 'comment' => 'Доставка'])->assertCreated()->assertJsonPath('data.is_active', true)->json('data.id');
        $this->getJson('/api/v1/suppliers?search=иван')->assertOk()->assertJsonPath('meta.total', 1);
        $this->patchJson('/api/v1/suppliers/'.$id, ['is_active' => false])->assertOk()->assertJsonPath('data.is_active', false);
        $this->getJson('/api/v1/suppliers?is_active=0')->assertJsonPath('meta.total', 1);
        $this->getJson('/api/v1/suppliers/'.$id)->assertOk();
        $this->deleteJson('/api/v1/suppliers/'.$id)->assertNoContent();
        $this->order();
        $this->deleteJson('/api/v1/suppliers/'.$this->supplier->id)->assertConflict();
    }

    public function test_draft_editing_totals_are_exact_and_ordered_purchase_is_not_editable(): void
    {
        $payload = $this->payload(['items' => [['product_id' => $this->product->id, 'quantity' => 3, 'unit_price' => '0.10'], ['product_id' => $this->second->id, 'quantity' => 2, 'unit_price' => '0.20']]]);
        $order = $this->postJson('/api/v1/purchase-orders', $payload)->assertCreated()->assertJsonPath('data.total_amount', '0.70')->json('data');
        $this->assertSame('0.30', $order['items'][0]['line_total']);
        unset($payload['request_id']);
        $payload['items'][0]['quantity'] = 5;
        $this->patchJson('/api/v1/purchase-orders/'.$order['id'], $payload)->assertOk()->assertJsonPath('data.total_amount', '0.90');
        $this->postJson('/api/v1/purchase-orders/'.$order['id'].'/order')->assertOk()->assertJsonPath('data.status', 'ordered');
        $this->patchJson('/api/v1/purchase-orders/'.$order['id'], $payload)->assertConflict();
        $this->getJson('/api/v1/purchase-orders?search='.urlencode($this->product->sku).'&status=ordered')->assertOk()->assertJsonPath('meta.total', 1);
        $this->assertDatabaseCount('warehouse_stocks', 0);
    }

    public function test_large_decimal_totals_do_not_lose_precision(): void
    {
        $this->postJson('/api/v1/purchase-orders', $this->payload(['items' => [['product_id' => $this->product->id, 'quantity' => 2147483647, 'unit_price' => '9999999999.99']]]))->assertCreated()->assertJsonPath('data.total_amount', '21474836469978525163.53');
    }

    public function test_partial_then_full_receipt_updates_order_stock_and_movements(): void
    {
        $order = $this->order();
        $receipt = $this->receipt($order, 4);
        $this->assertDatabaseCount('warehouse_stocks', 0);
        $this->assertDatabaseCount('stock_movements', 0);
        $this->postJson('/api/v1/goods-receipts/'.$receipt['id'].'/confirm')->assertOk()->assertJsonPath('data.status', 'confirmed')->assertJsonPath('data.purchase_order.status', 'partially_received')->assertJsonPath('data.purchase_order.items.0.remaining_quantity', 6)->assertJsonPath('meta.replayed', false);
        $this->assertSame(4, WarehouseStock::first()->quantity);
        $this->assertSame(4, StockMovement::first()->quantity_delta);
        $second = $this->receipt($order, 6);
        $this->postJson('/api/v1/goods-receipts/'.$second['id'].'/confirm')->assertOk()->assertJsonPath('data.purchase_order.status', 'received')->assertJsonPath('data.purchase_order.items.0.received_quantity', 10);
        $this->assertSame(10, WarehouseStock::first()->quantity);
        $this->assertDatabaseCount('stock_movements', 2);
        $this->getJson('/api/v1/stock-movements')->assertOk()->assertJsonPath('meta.total', 2);
        $this->getJson('/api/v1/goods-receipts?status=confirmed&purchase_order_id='.$order['id'])->assertOk()->assertJsonPath('meta.total', 2);
    }

    public function test_confirm_retries_and_create_retries_do_not_duplicate_documents_or_stock(): void
    {
        $payload = $this->payload();
        $order = $this->postJson('/api/v1/purchase-orders', $payload)->assertCreated()->json('data');
        $this->postJson('/api/v1/purchase-orders', $payload)->assertOk()->assertJsonPath('meta.replayed', true)->assertJsonPath('data.id', $order['id']);
        $this->postJson('/api/v1/purchase-orders', [...$payload, 'comment' => 'other'])->assertConflict();
        $this->postJson('/api/v1/purchase-orders/'.$order['id'].'/order')->assertOk();
        $data = ['request_id' => (string) Str::uuid(), 'purchase_order_id' => $order['id'], 'items' => [['purchase_order_item_id' => $order['items'][0]['id'], 'quantity' => 3]]];
        $receipt = $this->postJson('/api/v1/goods-receipts', $data)->assertCreated()->json('data');
        $this->postJson('/api/v1/goods-receipts', $data)->assertOk()->assertJsonPath('data.id', $receipt['id']);
        $this->postJson('/api/v1/goods-receipts', [...$data, 'comment' => 'other'])->assertConflict();
        $this->postJson('/api/v1/goods-receipts/'.$receipt['id'].'/confirm')->assertOk();
        $this->postJson('/api/v1/goods-receipts/'.$receipt['id'].'/confirm')->assertOk()->assertJsonPath('meta.replayed', true);
        $this->assertSame(3, WarehouseStock::first()->quantity);
        $this->assertDatabaseCount('stock_movements', 1);
        $this->assertDatabaseCount('goods_receipts', 1);
    }

    public function test_over_receipt_is_rejected_and_stale_draft_can_be_corrected(): void
    {
        $order = $this->order();
        $this->postJson('/api/v1/goods-receipts', ['request_id' => (string) Str::uuid(), 'purchase_order_id' => $order['id'], 'items' => [['purchase_order_item_id' => $order['items'][0]['id'], 'quantity' => 11]]])->assertUnprocessable();
        $this->assertDatabaseCount('goods_receipts', 0);
        $first = $this->receipt($order, 7);
        $second = $this->receipt($order, 7);
        $this->postJson('/api/v1/goods-receipts/'.$first['id'].'/confirm')->assertOk();
        $this->postJson('/api/v1/goods-receipts/'.$second['id'].'/confirm')->assertUnprocessable();
        $this->patchJson('/api/v1/goods-receipts/'.$second['id'], ['items' => [['purchase_order_item_id' => $order['items'][0]['id'], 'quantity' => 3]]])->assertOk();
        $this->postJson('/api/v1/goods-receipts/'.$second['id'].'/confirm')->assertOk()->assertJsonPath('data.purchase_order.status', 'received');
        $this->assertSame(10, WarehouseStock::first()->quantity);
    }

    public function test_confirmation_rolls_back_all_lines_if_stock_service_fails_on_later_line(): void
    {
        $order = $this->order(true, ['items' => [['product_id' => $this->product->id, 'quantity' => 2, 'unit_price' => '1.00'], ['product_id' => $this->second->id, 'quantity' => 2, 'unit_price' => '1.00']]]);
        $this->postJson('/api/v1/stock-operations', ['operation_id' => (string) Str::uuid(), 'type' => 'receipt', 'warehouse_id' => $this->warehouse->id, 'product_id' => $this->second->id, 'quantity' => 2147483647])->assertCreated();
        $receipt = $this->receipt($order, 2, ['items' => array_map(fn ($i) => ['purchase_order_item_id' => $i['id'], 'quantity' => 2], $order['items'])]);
        $this->postJson('/api/v1/goods-receipts/'.$receipt['id'].'/confirm')->assertUnprocessable();
        $this->assertDatabaseHas('goods_receipts', ['id' => $receipt['id'], 'status' => 'draft', 'confirmed_at' => null]);
        $this->assertSame(0, (int) PurchaseOrder::find($order['id'])->items()->sum('received_quantity'));
        $this->assertSame(0, WarehouseStock::where('product_id', $this->product->id)->count());
        $this->assertDatabaseCount('stock_movements', 1);
        $this->assertSame(0, DB::table('goods_receipt_items')->whereNotNull('stock_movement_id')->count());
    }

    public function test_cancelled_orders_and_receipts_cannot_be_received_and_confirmed_history_is_immutable(): void
    {
        $order = $this->order();
        $receipt = $this->receipt($order, 2);
        $this->postJson('/api/v1/goods-receipts/'.$receipt['id'].'/confirm')->assertOk();
        $draft = $this->receipt($order, 2);
        $this->postJson('/api/v1/purchase-orders/'.$order['id'].'/cancel')->assertOk()->assertJsonPath('data.status', 'cancelled');
        $this->getJson('/api/v1/goods-receipts/'.$draft['id'])->assertJsonPath('data.status', 'cancelled');
        $this->postJson('/api/v1/goods-receipts/'.$draft['id'].'/confirm')->assertConflict();
        $this->postJson('/api/v1/goods-receipts/'.$receipt['id'].'/cancel')->assertConflict();
        $this->patchJson('/api/v1/goods-receipts/'.$receipt['id'], ['items' => [['purchase_order_item_id' => $order['items'][0]['id'], 'quantity' => 1]]])->assertConflict();
        try {
            DB::transaction(fn () => DB::table('goods_receipts')->where('id', $receipt['id'])->update(['comment' => 'rewrite']));
            $this->fail('Confirmed documents must be immutable');
        } catch (QueryException $e) {
            $this->assertSame('23514', $e->getCode());
        }
        $this->assertSame(2, WarehouseStock::first()->quantity);
    }

    public function test_isolation_blocks_foreign_suppliers_warehouses_products_orders_and_items(): void
    {
        $order = $this->order();
        $receipt = $this->receipt($order, 2);
        $this->app['auth']->forgetGuards();
        $other = Organization::factory()->create();
        $otherUser = User::factory()->create();
        $otherUser->organizations()->attach($other);
        $supplier = Supplier::forceCreate(['organization_id' => $other->id, 'name' => 'Чужой']);
        $product = Product::factory()->create(['organization_id' => $other->id]);
        $warehouse = new Warehouse(['name' => 'Чужой', 'code' => 'OTHER', 'type' => 'own', 'is_active' => true]);
        $warehouse->organization_id = $other->id;
        $warehouse->save();
        $this->actingAs($this->user);
        foreach (['supplier_id' => $supplier->id, 'warehouse_id' => $warehouse->id] as $field => $value) {
            $this->postJson('/api/v1/purchase-orders', $this->payload([$field => $value]))->assertUnprocessable();
        }
        $this->postJson('/api/v1/purchase-orders', $this->payload(['items' => [['product_id' => $product->id, 'quantity' => 1, 'unit_price' => '1.00']]]))->assertUnprocessable();
        $this->getJson('/api/v1/suppliers/'.$supplier->id)->assertNotFound();
        $this->actingAs($otherUser);
        foreach (['purchase-orders/'.$order['id'], 'goods-receipts/'.$receipt['id']] as $path) {
            $this->getJson('/api/v1/'.$path)->assertNotFound();
        }
        $this->postJson('/api/v1/goods-receipts/'.$receipt['id'].'/confirm')->assertNotFound();
        $this->postJson('/api/v1/goods-receipts', ['request_id' => (string) Str::uuid(), 'purchase_order_id' => $order['id'], 'items' => [['purchase_order_item_id' => $order['items'][0]['id'], 'quantity' => 1]]])->assertUnprocessable();
        $this->getJson('/api/v1/purchase-orders')->assertJsonPath('meta.total', 0);
        $this->getJson('/api/v1/goods-receipts')->assertJsonPath('meta.total', 0);
        $this->getJson('/api/v1/suppliers')->assertJsonPath('meta.total', 1);
    }

    public function test_same_organization_cannot_receive_line_of_another_order(): void
    {
        $first = $this->order();
        $other = $this->order();
        $this->postJson('/api/v1/goods-receipts', ['request_id' => (string) Str::uuid(), 'purchase_order_id' => $first['id'], 'items' => [['purchase_order_item_id' => $other['items'][0]['id'], 'quantity' => 1]]])->assertUnprocessable();
        $this->assertDatabaseCount('goods_receipts', 0);
    }

    public function test_validation_authentication_and_membership(): void
    {
        $this->postJson('/api/v1/suppliers', ['name' => ' ', 'email' => 'wrong'])->assertUnprocessable();
        foreach (['-1.00', '1.001', '10000000000.00', '1e3', 12.34] as $price) {
            $this->postJson('/api/v1/purchase-orders', $this->payload(['items' => [['product_id' => $this->product->id, 'quantity' => 1, 'unit_price' => $price]]]))->assertUnprocessable();
        }
        $this->postJson('/api/v1/purchase-orders', $this->payload(['items' => []]))->assertUnprocessable();
        $this->postJson('/api/v1/purchase-orders', $this->payload(['organization_id' => $this->organization->id]))->assertUnprocessable();
        $this->postJson('/api/v1/purchase-orders', $this->payload(['items' => [['product_id' => $this->product->id, 'quantity' => 0, 'unit_price' => '1.00']]]))->assertUnprocessable();
        $this->user->organizations()->detach();
        foreach (['suppliers', 'purchase-orders', 'goods-receipts'] as $path) {
            $this->getJson('/api/v1/'.$path)->assertForbidden();
        }
        $this->app['auth']->forgetGuards();
        $this->getJson('/api/v1/purchase-orders')->assertUnauthorized();
    }

    public function test_policy_denial_and_inactive_destination_do_not_create_purchase(): void
    {
        $this->warehouse->update(['is_active' => false]);
        $this->postJson('/api/v1/purchase-orders', $this->payload())->assertUnprocessable();
        $this->assertDatabaseCount('purchase_orders', 0);
        Gate::before(fn () => false);
        $this->getJson('/api/v1/suppliers')->assertForbidden();
        $this->postJson('/api/v1/purchase-orders', $this->payload())->assertForbidden();
    }

    public function test_receipt_confirmation_utc_time_and_inactive_warehouse(): void
    {
        $order = $this->order();
        $receipt = $this->receipt($order, 2);
        $this->warehouse->update(['is_active' => false]);
        $this->postJson('/api/v1/goods-receipts/'.$receipt['id'].'/confirm')->assertUnprocessable();
        $this->assertDatabaseCount('stock_movements', 0);
        $this->warehouse->update(['is_active' => true]);
        DB::statement("SET LOCAL TIME ZONE 'Europe/Minsk'");
        $this->travelTo(Carbon::parse('2026-11-11T23:30:00+00:00'));
        $this->postJson('/api/v1/goods-receipts/'.$receipt['id'].'/confirm')->assertOk();
        $this->getJson('/api/v1/goods-receipts/'.$receipt['id'])->assertOk()->assertJsonPath('data.confirmed_at', '2026-11-11T23:30:00.000000Z');
    }
}
