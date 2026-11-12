<?php

namespace App\Http\Resources\Api\V1;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class PurchaseOrderResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id, 'supplier_id' => $this->supplier_id, 'warehouse_id' => $this->warehouse_id,
            'request_id' => $this->request_id, 'status' => $this->status, 'currency' => $this->currency, 'total_amount' => $this->total_amount, 'comment' => $this->comment,
            'supplier' => new SupplierResource($this->whenLoaded('supplier')), 'warehouse' => new WarehouseResource($this->whenLoaded('warehouse')),
            'creator' => $this->whenLoaded('creator', fn () => ['id' => $this->creator->id, 'name' => $this->creator->name]),
            'ordered_quantity' => $this->whenHas('ordered_quantity', fn () => (int) $this->ordered_quantity),
            'received_quantity' => $this->whenHas('received_quantity_total', fn () => (int) $this->received_quantity_total),
            'receipts_count' => $this->whenCounted('receipts'), 'items' => PurchaseOrderItemResource::collection($this->whenLoaded('items')),
            'created_at' => $this->created_at?->toISOString(), 'updated_at' => $this->updated_at?->toISOString(),
        ];
    }
}
