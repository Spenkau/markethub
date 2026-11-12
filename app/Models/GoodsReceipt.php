<?php

namespace App\Models;

use App\Models\Concerns\BelongsToOrganization;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class GoodsReceipt extends Model
{
    use BelongsToOrganization;

    protected $guarded = ['id'];

    public function setConfirmedAtAttribute($value): void
    {
        $this->attributes['confirmed_at'] = $value?->format('Y-m-d H:i:sP');
    }

    protected function casts(): array
    {
        return ['confirmed_at' => 'immutable_datetime'];
    }

    protected function validateBusinessRules(): void {}

    public function purchaseOrder(): BelongsTo
    {
        return $this->belongsTo(PurchaseOrder::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function confirmer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'confirmed_by');
    }

    public function items(): HasMany
    {
        return $this->hasMany(GoodsReceiptItem::class);
    }
}
