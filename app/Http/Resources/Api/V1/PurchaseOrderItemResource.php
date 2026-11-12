<?php

namespace App\Http\Resources\Api\V1;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class PurchaseOrderItemResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id, 'product_id' => $this->product_id, 'quantity' => $this->quantity, 'received_quantity' => $this->received_quantity,
            'remaining_quantity' => $this->quantity - $this->received_quantity, 'unit_price' => $this->unit_price, 'line_total' => $this->line_total,
            'product' => $this->whenLoaded('product', fn () => ['id' => $this->product->id, 'name' => $this->product->name, 'sku' => $this->product->sku]),
        ];
    }
}
