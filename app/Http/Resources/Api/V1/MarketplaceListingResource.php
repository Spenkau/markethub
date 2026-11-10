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
            'external_id' => $this->external_id, 'external_sku' => $this->external_sku,
            'price_minor' => $this->price_minor, 'currency' => $this->currency,
            'stock_quantity' => $this->stock_quantity, 'status' => $this->status,
            'product' => $this->whenLoaded('product', fn () => ['id' => $this->product->id, 'name' => $this->product->name, 'sku' => $this->product->sku]),
            'marketplace_account' => $this->whenLoaded('marketplaceAccount', fn () => ['id' => $this->marketplaceAccount->id, 'name' => $this->marketplaceAccount->name, 'marketplace' => $this->marketplaceAccount->marketplace, 'is_demo' => $this->marketplaceAccount->is_demo]),
            'created_at' => $this->created_at?->toISOString(), 'updated_at' => $this->updated_at?->toISOString(),
        ];
    }
}
