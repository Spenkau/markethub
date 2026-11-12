<?php

namespace Tests\Feature;

use App\Models\Organization;
use App\Models\Product;
use App\Models\StockMovement;
use App\Models\User;
use App\Models\Warehouse;
use App\Models\WarehouseStock;
use App\Services\StockOperationService;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Symfony\Component\Process\Process;
use Tests\TestCase;

class WarehouseConcurrencyTest extends TestCase
{
    use DatabaseMigrations;

    private User $user;

    private Warehouse $warehouse;

    private Warehouse $destination;

    private Product $product;

    protected function setUp(): void
    {
        parent::setUp();
        $org = Organization::factory()->create();
        $this->user = User::factory()->create();
        $this->user->organizations()->attach($org);
        $this->product = Product::factory()->create(['organization_id' => $org->id]);
        foreach (['warehouse' => 'A', 'destination' => 'B'] as $property => $code) {
            $this->{$property} = new Warehouse(['name' => $code, 'code' => $code, 'type' => 'own', 'is_active' => true]);
            $this->{$property}->organization_id = $org->id;
            $this->{$property}->save();
        }
        $this->actingAs($this->user);
    }

    private function payload(string $type, int $quantity, array $extra = []): array
    {
        return [...['operation_id' => (string) Str::uuid(), 'type' => $type, 'quantity' => $quantity, 'warehouse_id' => $this->warehouse->id, 'product_id' => $this->product->id], ...$extra];
    }

    private function concurrent(array $first, array $second): array
    {
        $connection = config('database.connections.pgsql');
        $environment = [
            'APP_ENV' => 'testing', 'DB_CONNECTION' => 'pgsql', 'DB_URL' => '',
            'DB_HOST' => $connection['host'], 'DB_PORT' => (string) $connection['port'],
            'DB_DATABASE' => $connection['database'], 'DB_USERNAME' => $connection['username'], 'DB_PASSWORD' => $connection['password'],
            'CACHE_STORE' => 'array', 'SESSION_DRIVER' => 'array',
        ];
        $name = 'mh_inventory_'.Str::random(8);
        $workers = [];
        DB::beginTransaction();
        try {
            Warehouse::whereKey($this->warehouse->id)->lockForUpdate()->firstOrFail();
            foreach ([$first, $second] as $index => $payload) {
                $worker = new Process([PHP_BINARY, base_path('tests/Support/stock_worker.php'), (string) $this->user->id, json_encode($payload, JSON_THROW_ON_ERROR), $name.$index], base_path(), $environment, timeout: 15);
                $worker->start();
                $workers[] = $worker;
            }
            // Prove both independent PostgreSQL connections actually overlap and wait on locks.
            $deadline = microtime(true) + 8;
            do {
                DB::select('SELECT pg_stat_clear_snapshot()');
                foreach ($workers as $worker) {
                    if (! $worker->isRunning()) {
                        $this->fail('Worker exited before lock: '.$worker->getErrorOutput().$worker->getOutput());
                    }
                }
                $waiting = DB::selectOne("SELECT COUNT(*) AS total FROM pg_stat_activity WHERE application_name LIKE ? AND wait_event_type = 'Lock'", [$name.'%'])->total;
                if ((int) $waiting === 2) {
                    break;
                }
                usleep(25000);
            } while (microtime(true) < $deadline);
            $this->assertSame(2, (int) $waiting, 'Both worker connections must overlap on database locks.');
            DB::commit();
            $statuses = [];
            foreach ($workers as $worker) {
                $worker->wait();
                $this->assertTrue($worker->isSuccessful(), $worker->getErrorOutput());
                $statuses[] = json_decode($worker->getOutput(), true, flags: JSON_THROW_ON_ERROR)['status'];
            }
            sort($statuses);

            return $statuses;
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

    public function test_concurrent_first_receipts_with_same_key_apply_exactly_once(): void
    {
        $payload = $this->payload('receipt', 8);
        $this->assertSame([200, 201], $this->concurrent($payload, $payload));
        $this->assertSame(8, WarehouseStock::first()->quantity);
        $this->assertDatabaseCount('warehouse_stocks', 1);
        $this->assertDatabaseCount('stock_movements', 1);
    }

    public function test_concurrent_withdrawals_cannot_spend_the_same_stock_twice(): void
    {
        app(StockOperationService::class)->execute($this->payload('receipt', 5), $this->user);
        $this->assertSame([201, 422], $this->concurrent($this->payload('write_off', 4), $this->payload('write_off', 4)));
        $this->assertSame(1, WarehouseStock::first()->quantity);
        $this->assertDatabaseCount('stock_movements', 2);
    }

    public function test_opposite_transfers_lock_in_the_same_order_and_preserve_total_quantity(): void
    {
        app(StockOperationService::class)->execute($this->payload('receipt', 10), $this->user);
        app(StockOperationService::class)->execute($this->payload('receipt', 10, ['warehouse_id' => $this->destination->id]), $this->user);
        $first = $this->payload('transfer', 3, ['destination_warehouse_id' => $this->destination->id]);
        $second = $this->payload('transfer', 4, ['warehouse_id' => $this->destination->id, 'destination_warehouse_id' => $this->warehouse->id]);
        $this->assertSame([201, 201], $this->concurrent($first, $second));
        $this->assertSame(11, WarehouseStock::where('warehouse_id', $this->warehouse->id)->value('quantity'));
        $this->assertSame(9, WarehouseStock::where('warehouse_id', $this->destination->id)->value('quantity'));
        $this->assertSame(20, (int) WarehouseStock::sum('quantity'));
        $this->assertSame(4, StockMovement::where('type', 'transfer')->count());
    }
}
