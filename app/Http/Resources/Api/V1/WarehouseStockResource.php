<?php

namespace App\Http\Resources\Api\V1;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class WarehouseStockResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id, 'warehouse_id' => $this->warehouse_id, 'product_id' => $this->product_id,
            'quantity' => $this->quantity, 'updated_at' => $this->updated_at?->toISOString(),
            'warehouse' => new WarehouseResource($this->whenLoaded('warehouse')),
            'product' => $this->whenLoaded('product', fn () => ['id' => $this->product->id, 'name' => $this->product->name, 'sku' => $this->product->sku]),
        ];
    }
}
