<?php

namespace App\Http\Resources\Api\V1;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class MarketplaceAccountResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id, 'organization_id' => $this->organization_id, 'name' => $this->name,
            'marketplace' => $this->marketplace, 'status' => $this->status, 'is_demo' => $this->is_demo,
            'listings_count' => $this->whenCounted('listings'),
            'listings_url' => url('/api/v1/marketplace-listings?marketplace_account_id='.$this->id),
            'created_at' => $this->created_at?->toISOString(), 'updated_at' => $this->updated_at?->toISOString(),
        ];
    }
}
