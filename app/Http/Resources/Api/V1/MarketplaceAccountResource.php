<?php

namespace App\Http\Resources\Api\V1;

use App\Models\MarketplaceSyncRun;
use App\Models\ShopifySyncRun;
use App\Services\Marketplaces\ConnectionService;
use App\Services\Marketplaces\ProviderRegistry;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Facades\DB;

class MarketplaceAccountResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $integration = $this->integrationSummary();

        return [
            'id' => $this->id, 'organization_id' => $this->organization_id, 'name' => $this->name,
            'marketplace' => $this->marketplace, 'status' => $this->status, 'is_demo' => $this->is_demo,
            'listings_count' => $this->whenCounted('listings'),
            'provider' => app(ProviderRegistry::class)->get($this->marketplace)->definition(),
            'configuration' => $this->configuration ?? [],
            'capabilities' => app(ConnectionService::class)->capabilities($this->resource),
            'integration' => [...$integration, 'latest_run' => $integration['latest_run'] ? MarketplaceSyncRun::withoutGlobalScopes()->where('organization_id', $this->organization_id)->find($integration['latest_run']->id) : null],
            // Deprecated compatibility alias consumed by the current Shopify UI.
            'shopify' => $this->marketplace === 'shopify' ? $integration : null,
            'listings_url' => url('/api/v1/marketplace-listings?marketplace_account_id='.$this->id),
            'created_at' => $this->created_at?->toISOString(), 'updated_at' => $this->updated_at?->toISOString(),
        ];
    }

    private function integrationSummary(): array
    {
        $rows = DB::table('marketplace_listings')->where('organization_id', $this->organization_id)->where('marketplace_account_id', $this->id);
        $stats = (clone $rows)->selectRaw('count(*) as variants, count(distinct marketplace_product_id) as products, count(*) filter (where product_id is null) as unmapped')->first();
        $run = ShopifySyncRun::where('organization_id', $this->organization_id)->where('marketplace_account_id', $this->id)->orderByDesc('created_at')->orderByDesc('id')->first();

        return [
            'domain' => $this->shop_domain, 'api_status' => $this->api_status, 'api_checked_at' => $this->api_checked_at?->toISOString(),
            'scopes' => $this->shopify_scopes ?? [], 'currency' => $this->shop_currency,
            'sync_frequency' => $this->sync_frequency, 'sync_products' => $this->sync_products, 'sync_inventory' => $this->sync_inventory,
            'initial_import_at' => $this->initial_import_at?->toISOString(), 'last_synced_at' => $this->last_synced_at?->toISOString(), 'next_sync_at' => $this->next_sync_at?->toISOString(),
            'products_count' => (int) $stats->products, 'variants_count' => (int) $stats->variants, 'unmapped_count' => (int) $stats->unmapped,
            'latest_run' => $run,
        ];
    }
}
