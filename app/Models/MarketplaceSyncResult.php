<?php

namespace App\Models;

use App\Models\Concerns\BelongsToOrganization;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class MarketplaceSyncResult extends Model
{
    use BelongsToOrganization;

    public $timestamps = false;

    protected $guarded = [];

    protected function validateBusinessRules(): void {}

    public function run(): BelongsTo
    {
        return $this->belongsTo(MarketplaceSyncRun::class, 'marketplace_sync_run_id');
    }
}
