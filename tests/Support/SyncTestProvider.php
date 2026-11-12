<?php

namespace Tests\Support;

use App\Models\MarketplaceAccount;
use App\Models\MarketplaceListing;
use App\Models\MarketplaceProduct;
use App\Models\MarketplaceSyncRun;
use App\Services\Marketplaces\DemoProvider;
use App\Services\Marketplaces\MarketplaceImportService;
use App\Services\Marketplaces\SyncException;
use Closure;
use Illuminate\Support\Facades\DB;

/** Only registered by isolated tests, never by the application. */
class SyncTestProvider extends DemoProvider
{
    public static int $executions = 0;

    public static int $failures = 0;

    public static int $pauseMs = 0;

    public static bool $unsafeFailure = false;

    public function definition(): array
    {
        return array_replace(parent::definition(), ['connection_type' => 'test', 'capabilities' => ['sync', 'products', 'inventory', 'refresh_listing', 'refresh_product']]);
    }

    public function importer(): ?Closure
    {
        return function (MarketplaceAccount $account, MarketplaceSyncRun $run) {
            self::$executions++;
            if (config('marketplaces.assert_no_transaction') && DB::transactionLevel() !== 0) {
                throw new \RuntimeException('External adapter must run outside a database transaction.');
            }
            if (self::$pauseMs) {
                usleep(self::$pauseMs * 1000);
            }
            if (config('marketplaces.fixture_listing')) {
                $listing = MarketplaceListing::withoutGlobalScopes()->where('organization_id', $run->organization_id)->findOrFail(config('marketplaces.fixture_listing'));
                $parent = MarketplaceProduct::withoutGlobalScopes()->findOrFail($listing->marketplace_product_id);
                app(MarketplaceImportService::class)->upsert($account,
                    ['external_id' => $parent->external_id, 'title' => 'Updated external', 'status' => 'active', 'images' => [], 'source_data' => [], 'source_synced_at' => now()->toIso8601String()],
                    ['external_id' => $listing->external_id, 'price_minor' => 250, 'currency' => 'USD', 'status' => 'active', 'product_id' => null], []);
            }
            if ($run->sync_products) {
                $run->recordResult('product', 'product-1');
                $run->recordResult('variant', 'variant-1', 'created');
            }
            if ($run->sync_inventory) {
                $run->recordResult('inventory', 'inventory-1');
            }
            $run->checkpoint(['phase' => 'fixture', 'cursor' => 'diagnostic-cursor', 'complete' => true]);
            if (self::$failures > 0) {
                self::$failures--;
                throw new SyncException('connection', true);
            }
            if (self::$unsafeFailure) {
                throw new \RuntimeException('SECRET-DO-NOT-LOG');
            }
        };
    }
}
