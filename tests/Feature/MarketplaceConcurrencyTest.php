<?php

namespace Tests\Feature;

use App\Models\MarketplaceAccount;
use App\Models\Organization;
use App\Models\Product;
use App\Services\Marketplaces\MarketplaceImportService;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Symfony\Component\Process\Process;
use Tests\TestCase;

class MarketplaceConcurrencyTest extends TestCase
{
    use DatabaseMigrations;

    public function test_overlapping_mapping_and_source_import_preserve_the_user_mapping(): void
    {
        $org = Organization::factory()->create();
        $account = MarketplaceAccount::factory()->create(['organization_id' => $org->id, 'marketplace' => 'ozon']);
        $local = Product::factory()->create(['organization_id' => $org->id]);
        $parent = ['external_id' => 'test-parent', 'title' => 'External', 'status' => 'active', 'images' => [], 'source_data' => [], 'source_synced_at' => now()->toIso8601String()];
        $variant = ['external_id' => 'test-variant', 'price_minor' => 100, 'currency' => 'USD', 'status' => 'active'];
        $listing = app(MarketplaceImportService::class)->upsert($account, $parent, $variant, []);
        $c = config('database.connections.pgsql');
        $environment = ['APP_ENV' => 'testing', 'DB_CONNECTION' => 'pgsql', 'DB_URL' => '', 'DB_HOST' => $c['host'], 'DB_PORT' => (string) $c['port'], 'DB_DATABASE' => $c['database'], 'DB_USERNAME' => $c['username'], 'DB_PASSWORD' => $c['password'], 'CACHE_STORE' => 'array', 'SESSION_DRIVER' => 'array'];
        $name = 'mh_mapping_'.Str::random(8);
        $workers = [];
        DB::beginTransaction();
        try {
            DB::table('marketplace_accounts')->where('id', $account->id)->lockForUpdate()->first();
            foreach (['map', 'import'] as $i => $action) {
                $payload = ['action' => $action, 'account' => $account->id, 'listing' => $listing['id'], 'product' => $local->id, 'parent' => $parent, 'variant' => [...$variant, 'price_minor' => 250]];
                $worker = new Process([PHP_BINARY, base_path('tests/Support/marketplace_worker.php'), json_encode($payload), $name.$i], base_path(), $environment, timeout: 20);
                $worker->start();
                $workers[] = $worker;
            }
            $waiting = 0;
            $deadline = microtime(true) + 10;
            do {
                DB::select('SELECT pg_stat_clear_snapshot()');
                $waiting = (int) DB::selectOne("SELECT count(*) AS total FROM pg_stat_activity WHERE application_name LIKE ? AND wait_event_type = 'Lock'", [$name.'%'])->total;
                if ($waiting === 2) {
                    break;
                }
                usleep(25000);
            } while (microtime(true) < $deadline);
            $this->assertSame(2, $waiting, 'Both independent database connections must overlap.');
            DB::commit();
            foreach ($workers as $worker) {
                $worker->wait();
                $this->assertTrue($worker->isSuccessful(), $worker->getErrorOutput().$worker->getOutput());
            }
            $this->assertDatabaseHas('marketplace_listings', ['id' => $listing['id'], 'product_id' => $local->id, 'price_minor' => 250]);
            $this->assertDatabaseCount('products', 1);
        } finally {
            if (DB::transactionLevel()) {
                DB::rollBack();
            }
            foreach ($workers as $worker) {
                if ($worker->isRunning()) {
                    $worker->stop();
                }
            }
            // Only isolated test data, before DatabaseMigrations invokes guarded down().
            DB::table('marketplace_listings')->delete();
            DB::table('marketplace_products')->delete();
        }
    }
}
