<?php

namespace App\Models;

use App\Models\Concerns\BelongsToOrganization;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class MarketplaceCredential extends Model
{
    use BelongsToOrganization;

    protected $guarded = ['id'];

    protected $hidden = ['payload'];

    protected $dateFormat = 'Y-m-d H:i:sP';

    protected function casts(): array
    {
        return ['payload' => 'encrypted:array', 'version' => 'integer'];
    }

    protected function validateBusinessRules(): void {}

    public function marketplaceAccount(): BelongsTo
    {
        return $this->belongsTo(MarketplaceAccount::class);
    }
}
