<?php

namespace App\Http\Resources\Api\V1;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class MarketplaceListingResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id, 'organization_id' => $this->organization_id,
            'product_id' => $this->product_id, 'marketplace_account_id' => $this->marketplace_account_id,
            'source_title' => $this->marketplaceProduct?->title, 'source_synced_at' => $this->source_synced_at?->toISOString(),
            'marketplace_product_id' => $this->marketplace_product_id, 'origin' => $this->origin, 'variant_title' => $this->variant_title, 'barcode' => $this->barcode,
            'source_status' => $this->source_status, 'source_created_at' => $this->source_created_at?->toISOString(), 'source_updated_at' => $this->source_updated_at?->toISOString(),
            'external_product' => $this->whenLoaded('marketplaceProduct', fn () => $this->marketplaceProduct ? ['id' => $this->marketplaceProduct->id, 'external_id' => $this->marketplaceProduct->external_id, 'title' => $this->marketplaceProduct->title, 'description' => $this->marketplaceProduct->description, 'images' => $this->marketplaceProduct->images, 'status' => $this->marketplaceProduct->status] : null),
            'external_id' => $this->external_id, 'external_sku' => $this->external_sku,
            'price_minor' => $this->price_minor, 'currency' => $this->currency,
            'quantities' => is_string($this->quantities) ? json_decode($this->quantities, true) : $this->quantities,
            'inventory_level_id' => $this->inventory_level_id,
            'marketplace_location_id' => $this->marketplace_location_id, 'location_name' => $this->location_name,
            'location_active' => $this->location_active === null ? null : (bool) $this->location_active,
            'warehouse_id' => $this->warehouse_id, 'tracked' => $this->tracked === null ? null : (bool) $this->tracked,
            'is_current' => $this->is_current === null ? null : (bool) $this->is_current, 'stale_reason' => $this->stale_reason,
            'inventory_source_synced_at' => $this->inventory_synced_at, 'inventory_source_updated_at' => $this->inventory_updated_at,
            'inventory_origin' => $this->origin === 'imported' ? 'external_snapshot' : ($this->origin === 'demo' ? 'demo' : 'local_listing'),
            'stock_quantity' => $this->stock_quantity, 'status' => $this->status,
            'product' => $this->whenLoaded('product', fn () => $this->product ? ['id' => $this->product->id, 'name' => $this->product->name, 'sku' => $this->product->sku] : null),
            'marketplace_account' => $this->whenLoaded('marketplaceAccount', fn () => ['id' => $this->marketplaceAccount->id, 'name' => $this->marketplaceAccount->name, 'marketplace' => $this->marketplaceAccount->marketplace, 'is_demo' => $this->marketplaceAccount->is_demo]),
            'created_at' => $this->created_at?->toISOString(), 'updated_at' => $this->updated_at?->toISOString(),
        ];
    }
}
