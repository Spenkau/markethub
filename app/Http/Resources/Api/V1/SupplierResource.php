<?php

namespace App\Http\Resources\Api\V1;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class SupplierResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id, 'name' => $this->name, 'contact_name' => $this->contact_name,
            'phone' => $this->phone, 'email' => $this->email, 'inn' => $this->inn, 'comment' => $this->comment, 'is_active' => $this->is_active,
            'purchase_orders_count' => $this->whenCounted('purchaseOrders'), 'created_at' => $this->created_at?->toISOString(), 'updated_at' => $this->updated_at?->toISOString(),
        ];
    }
}
