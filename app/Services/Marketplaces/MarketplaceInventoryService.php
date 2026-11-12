<?php

namespace App\Services\Marketplaces;

use App\Models\MarketplaceAccount;
use App\Models\MarketplaceSyncRun;
use Illuminate\Support\Facades\DB;

class MarketplaceInventoryService
{
    private function write(MarketplaceAccount $account, MarketplaceSyncRun $run, callable $callback): mixed
    {
        abort_unless((int) $run->organization_id === (int) $account->organization_id && (int) $run->marketplace_account_id === (int) $account->id, 422);

        return $run->guardedWrite(function () use ($account, $callback) {
            MarketplaceAccount::withoutGlobalScopes()->where('organization_id', $account->organization_id)->lockForUpdate()->findOrFail($account->id);

            return $callback();
        });
    }

    public function location(MarketplaceAccount $account, MarketplaceSyncRun $run, ExternalLocation $location): int
    {
        if (trim($location->externalId) === '' || trim($location->name) === '') {
            throw new SyncException('invalid_data');
        }

        return $this->write($account, $run, function () use ($account, $run, $location) {
            $key = ['organization_id' => $account->organization_id, 'marketplace_account_id' => $account->id, 'external_id' => $location->externalId];
            $old = DB::table('marketplace_locations')->where($key)->first();
            if ($old && strtotime($old->source_synced_at) > strtotime($location->syncedAt)) {
                throw new SyncException('source_changed', true);
            }
            $values = ['name' => $location->name, 'is_active' => $location->active, 'source_synced_at' => $location->syncedAt, 'seen_run' => $run->reconcile_token, 'metadata' => json_encode($location->metadata, JSON_THROW_ON_ERROR)];
            if ($old) {
                DB::table('marketplace_locations')->where('id', $old->id)->update([...$values, 'updated_at' => now()->toIso8601String()]);

                return $old->id;
            }

            return DB::table('marketplace_locations')->insertGetId([...$key, ...$values, 'created_at' => now()->toIso8601String(), 'updated_at' => now()->toIso8601String()]);
        });
    }

    public function snapshots(MarketplaceAccount $account, MarketplaceSyncRun $run, int $listingId, array $snapshots): void
    {
        $this->write($account, $run, function () use ($account, $run, $listingId, $snapshots) {
            DB::table('marketplace_listings')->where('organization_id', $account->organization_id)->where('marketplace_account_id', $account->id)->where('id', $listingId)->firstOrFail();
            abort_if($run->scope === 'variant' && (int) $run->target_listing_id !== $listingId, 422);
            if ($run->scope === 'product') {
                abort_unless(DB::table('marketplace_listings')->where('id', $listingId)->where('marketplace_product_id', $run->target_product_id)->exists(), 422);
            }
            foreach ($snapshots as $snapshot) {
                if (! $snapshot instanceof ExternalInventorySnapshot) {
                    throw new SyncException('invalid_data');
                }
                $location = DB::table('marketplace_locations')->where('organization_id', $account->organization_id)->where('marketplace_account_id', $account->id)->where('external_id', $snapshot->locationExternalId)->first();
                if (! $location) {
                    throw new SyncException('invalid_data');
                }
                $key = ['organization_id' => $account->organization_id, 'marketplace_account_id' => $account->id, 'marketplace_listing_id' => $listingId, 'marketplace_location_id' => $location->id];
                $old = DB::table('marketplace_inventory_levels')->where($key)->first();
                if ($old && ((strtotime($old->source_synced_at) > strtotime($snapshot->syncedAt)) || ($old->source_updated_at && $snapshot->updatedAt && strtotime($old->source_updated_at) > strtotime($snapshot->updatedAt)))) {
                    throw new SyncException('source_changed', true);
                }
                $values = ['available' => $snapshot->tracked ? $snapshot->available : null, 'tracked' => $snapshot->tracked, 'quantities' => json_encode($snapshot->quantities, JSON_THROW_ON_ERROR), 'metadata' => json_encode($snapshot->metadata, JSON_THROW_ON_ERROR), 'source_updated_at' => $snapshot->updatedAt, 'source_synced_at' => $snapshot->syncedAt, 'seen_run' => $run->reconcile_token, 'is_current' => (bool) $location->is_active, 'stale_reason' => $location->is_active ? null : 'location_inactive', 'updated_at' => now()->toIso8601String()];
                foreach (['on_hand', 'incoming', 'committed'] as $name) {
                    $values[$name] = $snapshot->tracked ? ($snapshot->quantities[$name] ?? null) : null;
                }
                // Legacy alias only; generic persistence does not interpret provider identifiers.
                $values['inventory_item_id'] = $snapshot->metadata['inventory_item_id'] ?? null;
                if ($old) {
                    DB::table('marketplace_inventory_levels')->where('id', $old->id)->update($values);
                } else {
                    DB::table('marketplace_inventory_levels')->insert([...$key, ...$values, 'created_at' => now()->toIso8601String()]);
                }
            }
        });
    }

    public function reconcileLocations(MarketplaceAccount $account, MarketplaceSyncRun $run, bool $complete): void
    {
        if (! $complete || $run->scope !== 'account') {
            return;
        }
        $this->write($account, $run, function () use ($account, $run) {
            $missing = DB::table('marketplace_locations')->where('organization_id', $account->organization_id)->where('marketplace_account_id', $account->id)->where(fn ($q) => $q->whereNull('seen_run')->orWhere('seen_run', '<>', $run->reconcile_token));
            $ids = (clone $missing)->pluck('id');
            $missing->update(['is_active' => false, 'updated_at' => now()->toIso8601String()]);
            DB::table('marketplace_inventory_levels')->where('organization_id', $account->organization_id)->where('marketplace_account_id', $account->id)->whereIn('marketplace_location_id', $ids)->update(['is_current' => false, 'stale_reason' => 'location_missing', 'updated_at' => now()->toIso8601String()]);
        });
    }

    public function reconcileListing(MarketplaceAccount $account, MarketplaceSyncRun $run, int $listingId, bool $complete): void
    {
        if (! $complete) {
            return;
        }
        $this->write($account, $run, function () use ($account, $run, $listingId) {
            abort_if($run->scope === 'variant' && (int) $run->target_listing_id !== $listingId, 422);
            if ($run->scope === 'product') {
                abort_unless(DB::table('marketplace_listings')->where('organization_id', $account->organization_id)->where('marketplace_account_id', $account->id)->where('id', $listingId)->where('marketplace_product_id', $run->target_product_id)->exists(), 422);
            }
            $query = DB::table('marketplace_inventory_levels')->where('organization_id', $account->organization_id)->where('marketplace_account_id', $account->id)->where('marketplace_listing_id', $listingId);
            (clone $query)->where(fn ($q) => $q->whereNull('seen_run')->orWhere('seen_run', '<>', $run->reconcile_token))->update(['is_current' => false, 'stale_reason' => 'missing', 'updated_at' => now()->toIso8601String()]);
            $stats = (clone $query)->where('is_current', true)->selectRaw('count(*) n, count(available) known, bool_and(tracked) tracked, sum(available) total')->first();
            // Compatibility cache has a legacy nonnegative constraint. Canonical reads use signed levels.
            $total = $stats->n && $stats->n === $stats->known && $stats->tracked && $stats->total >= 0 && $stats->total <= 2147483647 ? $stats->total : null;
            DB::table('marketplace_listings')->where('organization_id', $account->organization_id)->where('marketplace_account_id', $account->id)->where('id', $listingId)->update(['stock_quantity' => $total]);
        });
    }

    public function stale(MarketplaceAccount $account, MarketplaceSyncRun $run, ?int $listingId, string $reason): void
    {
        $this->write($account, $run, function () use ($account, $run, $listingId, $reason) {
            $listingId = $run->scope === 'variant' ? (int) $run->target_listing_id : $listingId;
            DB::table('marketplace_inventory_levels')->where('organization_id', $account->organization_id)->where('marketplace_account_id', $account->id)->when($listingId, fn ($q) => $q->where('marketplace_listing_id', $listingId))->when($run->scope === 'product', fn ($q) => $q->whereIn('marketplace_listing_id', DB::table('marketplace_listings')->where('organization_id', $account->organization_id)->where('marketplace_account_id', $account->id)->where('marketplace_product_id', $run->target_product_id)->select('id')))->update(['is_current' => false, 'stale_reason' => in_array($reason, ['source_deleted', 'api_error'], true) ? $reason : 'api_error', 'updated_at' => now()->toIso8601String()]);
        });
    }
}
