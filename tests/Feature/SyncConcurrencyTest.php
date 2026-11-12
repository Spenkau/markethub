<?php

namespace Tests\Feature;

use App\Models\MarketplaceAccount;
use App\Models\MarketplaceSyncRun;
use App\Models\Organization;
use App\Models\Product;
use App\Services\Marketplaces\ExternalLocation;
use App\Services\Marketplaces\MarketplaceImportService;
use App\Services\Marketplaces\MarketplaceInventoryService;
use App\Services\Marketplaces\SyncDispatcher;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Symfony\Component\Process\Process;
use Tests\Support\SyncTestProvider;
use Tests\TestCase;

class SyncConcurrencyTest extends TestCase
{
    use DatabaseMigrations;

    private MarketplaceAccount $account;

    private Product $product;

    private int $listing;

    protected function setUp(): void
    {
        parent::setUp();
        config(['marketplaces.providers.sync_test' => ['adapter' => SyncTestProvider::class, 'name' => 'Sync fixture']]);
        Queue::fake();
        $org = Organization::factory()->create();
        $this->account = MarketplaceAccount::factory()->create(['organization_id' => $org->id, 'marketplace' => 'sync_test', 'status' => 'active', 'is_demo' => false]);
        $this->account->forceFill(['initial_import_at' => now(), 'next_sync_at' => now()->subMinute()])->save();
        $this->product = Product::factory()->create(['organization_id' => $org->id]);
        $this->listing = app(MarketplaceImportService::class)->upsert($this->account,
            ['external_id' => 'parent', 'title' => 'External', 'status' => 'active', 'images' => [], 'source_data' => []],
            ['external_id' => 'variant', 'price_minor' => 100, 'currency' => 'USD', 'status' => 'active'], [])['id'];
    }

    protected function tearDown(): void
    {
        try {
            // DatabaseMigrations and this cleanup are guarded by Tests\TestCase (_test only).
            foreach (['marketplace_inventory_levels', 'marketplace_locations', 'marketplace_sync_results', 'marketplace_sync_runs', 'marketplace_listings', 'marketplace_products', 'marketplace_credentials', 'marketplace_accounts'] as $table) {
                DB::table($table)->delete();
            }
        } finally {
            parent::tearDown();
        }
    }

    private function process(array $data, string $name): Process
    {
        $c = config('database.connections.pgsql');
        $env = ['APP_ENV' => 'testing', 'DB_CONNECTION' => 'pgsql', 'DB_URL' => '', 'DB_HOST' => $c['host'], 'DB_PORT' => (string) $c['port'], 'DB_DATABASE' => $c['database'], 'DB_USERNAME' => $c['username'], 'DB_PASSWORD' => $c['password'], 'CACHE_STORE' => 'array', 'SESSION_DRIVER' => 'array'];

        return new Process([PHP_BINARY, base_path('tests/Support/sync_worker.php'), json_encode(['account' => $this->account->id, ...$data]), $name], base_path(), $env, timeout: 20);
    }

    private function overlapping(array $actions): array
    {
        $name = 'mh_sync_'.Str::random(8);
        $workers = [];
        DB::beginTransaction();
        try {
            DB::table('marketplace_accounts')->where('id', $this->account->id)->lockForUpdate()->first();
            foreach ($actions as $i => $data) {
                $worker = $this->process($data, $name.$i);
                $worker->start();
                $workers[] = $worker;
            }
            $waiting = 0;
            $deadline = microtime(true) + 10;
            do {
                DB::select('SELECT pg_stat_clear_snapshot()');
                $waiting = (int) DB::selectOne("SELECT count(*) total FROM pg_stat_activity WHERE application_name LIKE ? AND wait_event_type='Lock'", [$name.'%'])->total;
                if ($waiting === count($workers)) {
                    break;
                }usleep(25000);
            } while (microtime(true) < $deadline);
            $this->assertSame(count($workers), $waiting, 'Independent PostgreSQL sessions must overlap.');
            DB::commit();
            $results = [];
            foreach ($workers as $worker) {
                $worker->wait();
                $this->assertTrue($worker->isSuccessful(), $worker->getErrorOutput().$worker->getOutput());
                $results[] = json_decode($worker->getOutput(), true, flags: JSON_THROW_ON_ERROR);
            }

            return $results;
        } finally {
            if (DB::transactionLevel()) {
                DB::rollBack();
            }foreach ($workers as $worker) {
                if ($worker->isRunning()) {
                    $worker->stop();
                }
            }
        }
    }

    public function test_concurrent_manual_and_scheduled_requests_share_one_run(): void
    {
        $results = $this->overlapping([['action' => 'enqueue', 'trigger' => 'manual'], ['action' => 'enqueue', 'trigger' => 'scheduled']]);
        $this->assertSame(202, $results[0]['status']);
        $this->assertSame(202, $results[1]['status']);
        $this->assertSame($results[0]['id'], $results[1]['id']);
        $this->assertDatabaseCount('marketplace_sync_runs', 1);
    }

    public function test_concurrent_full_and_point_allow_only_one_active_scope(): void
    {
        $results = $this->overlapping([['action' => 'enqueue'], ['action' => 'enqueue', 'listing' => $this->listing]]);
        $statuses = array_column($results, 'status');
        sort($statuses);
        $this->assertSame([202, 409], $statuses);
        $this->assertDatabaseCount('marketplace_sync_runs', 1);
    }

    public function test_two_workers_with_duplicate_delivery_execute_the_run_once(): void
    {
        $run = app(SyncDispatcher::class)->enqueue($this->account, 'manual', null, true);
        $first = $this->process(['action' => 'job', 'run' => $run->id], 'worker_one');
        $second = $this->process(['action' => 'job', 'run' => $run->id], 'worker_two');
        try {
            $first->start();
            $deadline = microtime(true) + 8;
            do {
                $running = $run->fresh()->status === 'running';
                if ($running) {
                    break;
                } usleep(10000);
            } while (microtime(true) < $deadline);
            $this->assertTrue($running);
            $second->start();
            $second->wait();
            $this->assertTrue($first->isRunning(), 'The second delivery must overlap the live worker.');
            $first->wait();
            $this->assertTrue($first->isSuccessful(), $first->getErrorOutput());
            $this->assertTrue($second->isSuccessful(), $second->getErrorOutput());
            $this->assertSame('completed', $run->fresh()->status);
            $this->assertSame(1, $run->fresh()->attempt);
            $this->assertSame(2, $run->fresh()->processed);
            $this->assertDatabaseCount('marketplace_sync_results', 3);
        } finally {
            if ($first->isRunning()) {
                $first->stop();
            }if ($second->isRunning()) {
                $second->stop();
            }
        }
    }

    public function test_job_and_mapping_overlap_without_overwriting_product_id(): void
    {
        $run = app(SyncDispatcher::class)->enqueue($this->account, 'manual', null, true);
        $this->overlapping([['action' => 'job', 'run' => $run->id, 'listing' => $this->listing], ['action' => 'map', 'listing' => $this->listing, 'product' => $this->product->id]]);
        $this->assertDatabaseHas('marketplace_listings', ['id' => $this->listing, 'product_id' => $this->product->id, 'price_minor' => 250]);
        $this->assertSame('completed', $run->fresh()->status);
    }

    public function test_publication_failure_after_actual_commit_is_visible_and_recoverable_in_another_process(): void
    {
        $worker = $this->process(['action' => 'publish_fail'], 'outbox_failure');
        $worker->run();
        $this->assertTrue($worker->isSuccessful(), $worker->getErrorOutput());
        $result = json_decode($worker->getOutput(), true, flags: JSON_THROW_ON_ERROR);
        $run = MarketplaceSyncRun::withoutGlobalScopes()->findOrFail($result['id']);
        $this->assertSame('pending', $run->dispatch_state);
        $run->forceFill(['next_dispatch_at' => now()->subSecond()])->save();
        $recovery = $this->process(['action' => 'recover'], 'outbox_recovery');
        $recovery->run();
        $this->assertTrue($recovery->isSuccessful(), $recovery->getErrorOutput());
        $this->assertSame(1, json_decode($recovery->getOutput(), true)['published']);
        $this->assertSame('published', $run->fresh()->dispatch_state);
        $this->assertDatabaseCount('marketplace_sync_runs', 1);
    }

    public function test_inventory_writers_overlap_and_keep_one_snapshot_and_location_mapping(): void
    {
        $run = app(SyncDispatcher::class)->enqueue($this->account, 'manual', null, true);
        $run->update(['status' => 'running', 'attempt' => 1, 'reconcile_token' => (string) Str::uuid()]);
        $store = app(MarketplaceInventoryService::class);
        $location = $store->location($this->account, $run, new ExternalLocation('concurrent', 'Shared', true, '2026-11-12T12:00:00Z'));
        $warehouse = DB::table('warehouses')->insertGetId(['organization_id' => $this->account->organization_id, 'name' => 'Main', 'code' => 'MAIN', 'type' => 'own', 'is_active' => true]);
        DB::table('marketplace_locations')->where('id', $location)->update(['warehouse_id' => $warehouse]);
        $this->overlapping([['action' => 'inventory', 'run' => $run->id, 'listing' => $this->listing, 'quantity' => -2], ['action' => 'inventory', 'run' => $run->id, 'listing' => $this->listing, 'quantity' => 4]]);
        $this->assertSame(1, DB::table('marketplace_inventory_levels')->count());
        $this->assertContains(DB::table('marketplace_inventory_levels')->value('available'), [-2, 4]);
        $this->assertSame($warehouse, DB::table('marketplace_locations')->value('warehouse_id'));
        $this->assertSame(0, DB::table('warehouse_stocks')->count());
        $this->assertSame(0, DB::table('stock_movements')->count());
    }
}
