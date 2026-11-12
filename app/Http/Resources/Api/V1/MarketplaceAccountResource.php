<?php

namespace App\Http\Resources\Api\V1;

use App\Models\ShopifySyncRun;
use App\Services\Marketplaces\ConnectionReadService;
use App\Services\Marketplaces\ConnectionService;
use App\Services\Marketplaces\ProviderRegistry;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

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
            'integration' => $integration,
            // Deprecated compatibility alias consumed by the current Shopify UI.
            'shopify' => $this->marketplace === 'shopify' ? [...$integration, 'latest_run' => $integration['latest_run'] ? (new ShopifySyncRun)->setRawAttributes($integration['latest_run']->getAttributes()) : null] : null,
            'listings_url' => url('/api/v1/marketplace-listings?marketplace_account_id='.$this->id),
            'created_at' => $this->created_at?->toISOString(), 'updated_at' => $this->updated_at?->toISOString(),
        ];
    }

    private function integrationSummary(): array
    {
        if (! $this->resource->relationLoaded('syncSummary')) {
            app(ConnectionReadService::class)->attach(collect([$this->resource]));
        }
        $summary = $this->resource->getRelation('syncSummary');
        $stats = $summary['stats'];
        $run = $summary['run'];

        return [
            'domain' => $this->shop_domain, 'api_status' => $this->api_status, 'api_checked_at' => $this->api_checked_at?->toISOString(),
            'scopes' => $this->shopify_scopes ?? [], 'currency' => $this->shop_currency,
            'sync_frequency' => $this->sync_frequency, 'sync_products' => $this->sync_products, 'sync_inventory' => $this->sync_inventory,
            'initial_import_at' => $this->initial_import_at?->toISOString(), 'last_synced_at' => $this->last_synced_at?->toISOString(), 'next_sync_at' => $this->next_sync_at?->toISOString(),
            'products_count' => (int) ($stats?->products ?? 0), 'variants_count' => (int) ($stats?->variants ?? 0), 'unmapped_count' => (int) ($stats?->unmapped ?? 0),
            'latest_run' => $run,
        ];
    }
}
