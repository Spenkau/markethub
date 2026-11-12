<?php

use App\Jobs\SyncMarketplace;
use App\Models\MarketplaceAccount;
use App\Models\MarketplaceSyncRun;
use App\Services\Marketplaces\CatalogMappingService;
use App\Services\Marketplaces\ExternalInventorySnapshot;
use App\Services\Marketplaces\MarketplaceInventoryService;
use App\Services\Marketplaces\SyncDispatcher;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\Support\SyncTestProvider;

require dirname(__DIR__, 2).'/vendor/autoload.php';
$app = require dirname(__DIR__, 2).'/bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();
if (config('database.default') !== 'pgsql' || ! str_ends_with(config('database.connections.pgsql.database'), '_test')) {
    throw new LogicException('Isolated test database required.');
}
config(['marketplaces.providers.sync_test' => ['adapter' => SyncTestProvider::class, 'name' => 'Sync fixture']]);
Queue::fake();
$data = json_decode($argv[1], true, flags: JSON_THROW_ON_ERROR);
DB::select("SELECT set_config('application_name', ?, false)", [$argv[2]]);
$account = MarketplaceAccount::withoutGlobalScopes()->findOrFail($data['account']);
try {
    if ($data['action'] === 'inventory') {
        $run = MarketplaceSyncRun::withoutGlobalScopes()->findOrFail($data['run']);
        app(MarketplaceInventoryService::class)->snapshots($account, $run, $data['listing'], [new ExternalInventorySnapshot('concurrent', true, $data['quantity'], [], null, '2026-11-12T12:00:00Z')]);
        echo json_encode(['status' => 200]);
    } elseif ($data['action'] === 'job') {
        SyncTestProvider::$pauseMs = 2000;
        config(['marketplaces.fixture_listing' => $data['listing'] ?? null, 'marketplaces.assert_no_transaction' => true]);
        (new SyncMarketplace($data['run']))->handle();
        echo json_encode(['status' => 200]);
    } elseif ($data['action'] === 'map') {
        app(CatalogMappingService::class)->map($account, $data['listing'], $data['product']);
        echo json_encode(['status' => 200]);
    } elseif ($data['action'] === 'recover') {
        echo json_encode(['status' => 200, 'published' => app(SyncDispatcher::class)->recover()]);
    } else {
        if ($data['action'] === 'publish_fail') {
            Queue::swap(new class
            {
                public function connection($name)
                {
                    return $this;
                }

                public function push(...$arguments)
                {
                    throw new RuntimeException('SECRET-REDIS-CONNECTION');
                }
            });
        }
        $run = app(SyncDispatcher::class)->enqueue($account, $data['trigger'] ?? 'manual', $data['listing'] ?? null, true);
        echo json_encode(['status' => 202, 'id' => $run->id, 'dispatch_state' => $run->dispatch_state]);
    }
} catch (HttpException $e) {
    echo json_encode(['status' => $e->getStatusCode()]);
}
