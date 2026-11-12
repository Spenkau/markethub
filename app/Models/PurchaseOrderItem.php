<?php

namespace App\Models;

use App\Models\Concerns\BelongsToOrganization;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PurchaseOrderItem extends Model
{
    use BelongsToOrganization;

    protected $guarded = ['id'];

    public $timestamps = false;

    protected function casts(): array
    {
        return ['quantity' => 'integer', 'received_quantity' => 'integer', 'unit_price' => 'decimal:2', 'line_total' => 'decimal:2'];
    }

    protected function validateBusinessRules(): void {}

    public function purchaseOrder(): BelongsTo
    {
        return $this->belongsTo(PurchaseOrder::class);
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }
}
