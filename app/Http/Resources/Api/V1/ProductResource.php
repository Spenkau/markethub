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
            'listings_url' => url('/api/v1/marketplace-listings?product_id='.$this->id),
            'created_at' => $this->created_at?->toISOString(), 'updated_at' => $this->updated_at?->toISOString(),
        ];
    }
}
