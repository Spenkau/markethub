<?php

namespace App\Http\Resources\Api\V1;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class ProductResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id, 'organization_id' => $this->organization_id,
            'sku' => $this->sku, 'name' => $this->name, 'description' => $this->description,
            'price_minor' => $this->base_price_minor, 'currency' => $this->currency, 'is_active' => $this->is_active,
            'listings_count' => $this->whenCounted('listings'),
            'external_listings' => $this->whenLoaded('listings', fn () => $this->listings->map(fn ($l) => [
                'id' => $l->id, 'account_id' => $l->marketplace_account_id, 'account_name' => $l->marketplaceAccount?->name,
                'external_id' => $l->external_id, 'price_minor' => $l->price_minor, 'currency' => $l->currency,
                'status' => $l->source_status, 'synced_at' => $l->source_synced_at?->toISOString(),
            ])),
            // Compatibility alias; new clients use external_listings.
            'shopify_listings' => $this->whenLoaded('listings', fn () => $this->listings->filter(fn ($l) => $l->marketplaceAccount?->marketplace === 'shopify')->values()->map(fn ($l) => [
                'id' => $l->id, 'account_id' => $l->marketplace_account_id, 'account_name' => $l->marketplaceAccount?->name,
                'external_id' => $l->external_id, 'price_minor' => $l->price_minor, 'currency' => $l->currency,
                'status' => $l->source_status, 'synced_at' => $l->source_synced_at?->toISOString(),
            ])),
            'listings_url' => url('/api/v1/marketplace-listings?product_id='.$this->id),
            'created_at' => $this->created_at?->toISOString(), 'updated_at' => $this->updated_at?->toISOString(),
        ];
    }
}
