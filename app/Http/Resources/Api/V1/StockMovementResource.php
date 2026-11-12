<?php

namespace App\Http\Resources\Api\V1;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class StockMovementResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id, 'operation_id' => $this->operation_id, 'type' => $this->type,
            'warehouse_id' => $this->warehouse_id, 'product_id' => $this->product_id,
            'quantity_delta' => $this->quantity_delta, 'quantity_before' => $this->quantity_before, 'quantity_after' => $this->quantity_after,
            'document' => $this->whenLoaded('receiptItem', fn () => $this->receiptItem ? ['type' => 'goods_receipt', 'id' => $this->receiptItem->goods_receipt_id, 'purchase_order_id' => $this->receiptItem->purchase_order_id] : null),
            'comment' => $this->comment, 'occurred_at' => $this->occurred_at->toISOString(),
            'warehouse' => new WarehouseResource($this->whenLoaded('warehouse')),
            'product' => $this->whenLoaded('product', fn () => ['id' => $this->product->id, 'name' => $this->product->name, 'sku' => $this->product->sku]),
            'user' => $this->whenLoaded('user', fn () => ['id' => $this->user->id, 'name' => $this->user->name]),
        ];
    }
}
