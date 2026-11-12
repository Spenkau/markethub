<?php

namespace App\Models;

use App\Models\Concerns\BelongsToOrganization;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class MarketplaceProduct extends Model
{
    use BelongsToOrganization;

    protected $guarded = ['id'];

    protected $dateFormat = 'Y-m-d H:i:sP';

    protected function casts(): array
    {
        return ['images' => 'array', 'source_data' => 'array', 'source_created_at' => 'immutable_datetime', 'source_updated_at' => 'immutable_datetime', 'source_synced_at' => 'immutable_datetime'];
    }

    protected function validateBusinessRules(): void {}

    public function marketplaceAccount(): BelongsTo
    {
        return $this->belongsTo(MarketplaceAccount::class);
    }

    public function listings(): HasMany
    {
        return $this->hasMany(MarketplaceListing::class);
    }
}
