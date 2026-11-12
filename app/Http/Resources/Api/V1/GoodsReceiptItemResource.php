<?php

namespace App\Http\Resources\Api\V1;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class GoodsReceiptItemResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id, 'purchase_order_item_id' => $this->purchase_order_item_id, 'product_id' => $this->product_id, 'quantity' => $this->quantity,
            'stock_movement_id' => $this->stock_movement_id,
            'stock_operation_id' => $this->stock_movement_id ? $this->operation_id : null,
            'product' => $this->whenLoaded('product', fn () => ['id' => $this->product->id, 'name' => $this->product->name, 'sku' => $this->product->sku]),
        ];
    }
}
