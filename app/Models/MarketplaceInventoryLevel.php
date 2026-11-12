<?php

namespace App\Models;

use App\Models\Concerns\BelongsToOrganization;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class MarketplaceInventoryLevel extends Model
{
    use BelongsToOrganization;

    protected $guarded = ['id'];

    protected $dateFormat = 'Y-m-d H:i:sP';

    protected function casts(): array
    {
        return ['metadata' => 'array', 'quantities' => 'array', 'tracked' => 'boolean', 'is_current' => 'boolean', 'available' => 'integer', 'source_synced_at' => 'immutable_datetime', 'source_updated_at' => 'immutable_datetime'];
    }

    protected function validateBusinessRules(): void {}

    public function marketplaceAccount(): BelongsTo
    {
        return $this->belongsTo(MarketplaceAccount::class);
    }

    public function marketplaceListing(): BelongsTo
    {
        return $this->belongsTo(MarketplaceListing::class);
    }

    public function marketplaceLocation(): BelongsTo
    {
        return $this->belongsTo(MarketplaceLocation::class);
    }
}
