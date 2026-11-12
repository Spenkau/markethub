<?php

namespace Tests\Feature;

use App\Models\GoodsReceipt;
use App\Models\Organization;
use App\Models\Product;
use App\Models\PurchaseOrder;
use App\Models\Supplier;
use App\Models\User;
use App\Models\Warehouse;
use App\Models\WarehouseStock;
use App\Services\ProcurementService;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Symfony\Component\Process\Process;
use Tests\TestCase;

class ProcurementConcurrencyTest extends TestCase
{
    use DatabaseMigrations;

    private User $user;

    private PurchaseOrder $order;

    protected function setUp(): void
    {
        parent::setUp();
        $org = Organization::factory()->create();
        $this->user = User::factory()->create();
        $this->user->organizations()->attach($org);
        $supplier = Supplier::forceCreate(['organization_id' => $org->id, 'name' => 'Поставщик']);
        $warehouse = new Warehouse(['name' => 'Склад', 'code' => 'MAIN', 'type' => 'own', 'is_active' => true]);
        $warehouse->organization_id = $org->id;
        $warehouse->save();
        $product = Product::factory()->create(['organization_id' => $org->id]);
        $this->actingAs($this->user);
        $service = app(ProcurementService::class);
        $this->order = $service->createOrder(['request_id' => (string) Str::uuid(), 'supplier_id' => $supplier->id, 'warehouse_id' => $warehouse->id, 'currency' => 'RUB', 'items' => [['product_id' => $product->id, 'quantity' => 10, 'unit_price' => '1.00']]], $this->user)['record'];
        $this->order = $service->transition($this->order, 'order', $this->user);
    }

    private function receipt(int $quantity): GoodsReceipt
    {
        return app(ProcurementService::class)->createReceipt(['request_id' => (string) Str::uuid(), 'purchase_order_id' => $this->order->id, 'items' => [['purchase_order_item_id' => $this->order->items->first()->id, 'quantity' => $quantity]]], $this->user)['record'];
    }

    private function concurrent(GoodsReceipt $first, GoodsReceipt $second): array
    {
        $config = config('database.connections.pgsql');
        $environment = ['APP_ENV' => 'testing', 'DB_CONNECTION' => 'pgsql', 'DB_URL' => '', 'DB_HOST' => $config['host'], 'DB_PORT' => (string) $config['port'], 'DB_DATABASE' => $config['database'], 'DB_USERNAME' => $config['username'], 'DB_PASSWORD' => $config['password'], 'CACHE_STORE' => 'array', 'SESSION_DRIVER' => 'array'];
        $name = 'mh_procurement_'.Str::random(8);
        $workers = [];
        DB::beginTransaction();
        try {
            PurchaseOrder::whereKey($this->order->id)->lockForUpdate()->firstOrFail();
            foreach ([$first, $second] as $index => $receipt) {
                $process = new Process([PHP_BINARY, base_path('tests/Support/receipt_worker.php'), (string) $this->user->id, (string) $receipt->id, $name.$index], base_path(), $environment, timeout: 15);
                $process->start();
                $workers[] = $process;
            }
            $deadline = microtime(true) + 8;
            do {
                DB::select('SELECT pg_stat_clear_snapshot()');
                foreach ($workers as $worker) {
                    if (! $worker->isRunning()) {
                        $this->fail($worker->getErrorOutput().$worker->getOutput());
                    }
                }
                $waiting = DB::selectOne("SELECT COUNT(*) AS total FROM pg_stat_activity WHERE application_name LIKE ? AND wait_event_type = 'Lock'", [$name.'%'])->total;
                if ((int) $waiting === 2) {
                    break;
                }
                usleep(25000);
            } while (microtime(true) < $deadline);
            $this->assertSame(2, (int) $waiting, 'Both confirmations must overlap on PostgreSQL locks.');
            DB::commit();
            $results = [];
            foreach ($workers as $worker) {
                $worker->wait();
                $this->assertTrue($worker->isSuccessful(), $worker->getErrorOutput());
                $results[] = json_decode($worker->getOutput(), true, flags: JSON_THROW_ON_ERROR);
            }

            return $results;
        } finally {
            if (DB::transactionLevel() > 0) {
                DB::rollBack();
            }
            foreach ($workers as $worker) {
                if ($worker->isRunning()) {
                    $worker->stop();
                }
            }
        }
    }

    public function test_simultaneous_confirmation_of_one_receipt_posts_it_once(): void
    {
        $receipt = $this->receipt(4);
        $results = $this->concurrent($receipt, $receipt);
        $this->assertSame([200, 200], array_column($results, 'status'));
        $replayed = array_column($results, 'replayed');
        sort($replayed);
        $this->assertSame([false, true], $replayed);
        $this->assertSame(4, WarehouseStock::first()->quantity);
        $this->assertDatabaseCount('stock_movements', 1);
        $this->assertSame(4, (int) $this->order->items()->sum('received_quantity'));
    }

    public function test_competing_receipts_cannot_exceed_ordered_quantity(): void
    {
        $results = $this->concurrent($this->receipt(7), $this->receipt(7));
        $statuses = array_column($results, 'status');
        sort($statuses);
        $this->assertSame([200, 422], $statuses);
        $this->assertSame(7, WarehouseStock::first()->quantity);
        $this->assertDatabaseCount('stock_movements', 1);
        $this->assertSame(1, GoodsReceipt::where('status', 'confirmed')->count());
    }

    public function test_concurrent_partial_receipts_can_complete_order_without_lost_updates(): void
    {
        $results = $this->concurrent($this->receipt(3), $this->receipt(7));
        $this->assertSame([200, 200], array_column($results, 'status'));
        $this->assertSame(10, WarehouseStock::first()->quantity);
        $this->assertSame('received', $this->order->fresh()->status);
        $this->assertDatabaseCount('stock_movements', 2);
    }
}
