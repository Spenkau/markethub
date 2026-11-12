<?php

namespace App\Http\Resources\Api\V1;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class GoodsReceiptResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id, 'request_id' => $this->request_id, 'purchase_order_id' => $this->purchase_order_id,
            'status' => $this->status, 'comment' => $this->comment, 'confirmed_at' => $this->confirmed_at?->toISOString(),
            'purchase_order' => new PurchaseOrderResource($this->whenLoaded('purchaseOrder')),
            'creator' => $this->whenLoaded('creator', fn () => ['id' => $this->creator->id, 'name' => $this->creator->name]),
            'confirmer' => $this->whenLoaded('confirmer', fn () => $this->confirmer ? ['id' => $this->confirmer->id, 'name' => $this->confirmer->name] : null),
            'total_quantity' => $this->whenHas('total_quantity', fn () => (int) $this->total_quantity),
            'items' => GoodsReceiptItemResource::collection($this->whenLoaded('items')),
            'created_at' => $this->created_at?->toISOString(), 'updated_at' => $this->updated_at?->toISOString(),
        ];
    }
}
