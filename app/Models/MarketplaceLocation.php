<?php

namespace App\Models;

use App\Models\Concerns\BelongsToOrganization;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class MarketplaceLocation extends Model
{
    use BelongsToOrganization;

    protected $guarded = ['id'];

    protected $dateFormat = 'Y-m-d H:i:sP';

    protected function casts(): array
    {
        return ['metadata' => 'array', 'is_active' => 'boolean', 'source_synced_at' => 'immutable_datetime'];
    }

    protected function validateBusinessRules(): void {}

    public function marketplaceAccount(): BelongsTo
    {
        return $this->belongsTo(MarketplaceAccount::class);
    }

    public function warehouse(): BelongsTo
    {
        return $this->belongsTo(Warehouse::class);
    }

    public function inventoryLevels(): HasMany
    {
        return $this->hasMany(MarketplaceInventoryLevel::class);
    }
}
