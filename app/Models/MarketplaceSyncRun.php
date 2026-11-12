<?php

namespace App\Models;

use App\Models\Concerns\BelongsToOrganization;
use App\Services\Marketplaces\SyncException;
use App\Services\Marketplaces\SyncProgress;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class MarketplaceSyncRun extends Model
{
    use BelongsToOrganization;

    public $incrementing = false;

    protected $table = 'marketplace_sync_runs';

    protected $keyType = 'string';

    protected $dateFormat = 'Y-m-d H:i:sP';

    protected $guarded = [];

    private ?string $executionLease = null;

    public function bindExecutionLease(string $lease): void
    {
        if ($this->executionLease !== null && $this->executionLease !== $lease) {
            throw new SyncException('stale_attempt');
        }
        $this->executionLease = $lease;
    }

    public function executionLease(): ?string
    {
        return $this->executionLease ?? $this->reconcile_token;
    }

    protected function casts(): array
    {
        return ['sync_products' => 'boolean', 'sync_inventory' => 'boolean', 'full_reconcile' => 'boolean', 'attempt' => 'integer',
            'details' => 'array', 'checkpoint' => 'array', 'attempt_progress' => 'array', 'attempt_errors' => 'array',
            'started_at' => 'immutable_datetime', 'finished_at' => 'immutable_datetime', 'heartbeat_at' => 'immutable_datetime',
            'attempt_started_at' => 'immutable_datetime', 'dispatch_lease_at' => 'immutable_datetime', 'dispatched_at' => 'immutable_datetime', 'next_dispatch_at' => 'immutable_datetime'];
    }

    protected function validateBusinessRules(): void {}

    public function marketplaceAccount(): BelongsTo
    {
        return $this->belongsTo(MarketplaceAccount::class);
    }

    public function targetListing(): BelongsTo
    {
        return $this->belongsTo(MarketplaceListing::class, 'target_listing_id');
    }

    public function targetProduct(): BelongsTo
    {
        return $this->belongsTo(MarketplaceProduct::class, 'target_product_id');
    }

    public function results(): HasMany
    {
        return $this->hasMany(MarketplaceSyncResult::class);
    }

    public function recordResult(string $kind, string $id, string $outcome = 'processed'): void
    {
        app(SyncProgress::class)->result($this, $kind, $id, $outcome);
    }

    public function recordError(string $reference, string $code): void
    {
        app(SyncProgress::class)->error($this, $reference, $code);
    }

    public function checkpoint(array $data): void
    {
        app(SyncProgress::class)->checkpoint($this, $data);
    }

    public function heartbeat(): void
    {
        app(SyncProgress::class)->heartbeat($this);
    }

    public function toArray(): array
    {
        $data = parent::toArray();
        if (($data['trigger'] ?? null) === 'automatic') {
            $data['trigger'] = 'scheduled';
        }
        if (($data['scope'] ?? 'account') !== 'account') {
            $data['full_reconcile'] = false;
        }

        return $data;
    }

    public function guardedWrite(callable $write): mixed
    {
        return app(SyncProgress::class)->write($this, $write);
    }
}
