<?php

namespace App\Services\Marketplaces;

use App\Models\MarketplaceSyncRun;
use Illuminate\Support\Facades\DB;

class SyncProgress
{
    /** Fence short database writes with the current attempt, never external HTTP. */
    public function write(MarketplaceSyncRun $run, callable $write): mixed
    {
        return DB::transaction(function () use ($run, $write) {
            $owner = MarketplaceSyncRun::withoutGlobalScopes()->where('organization_id', $run->organization_id)->where('marketplace_account_id', $run->marketplace_account_id)
                ->whereKey($run->id)->where('reconcile_token', $run->executionLease())->where('status', 'running')->lockForUpdate()->first();
            if (! $owner) {
                throw new SyncException('stale_attempt');
            }

            return $write();
        });
    }

    public function heartbeat(MarketplaceSyncRun $run): void
    {
        if ($run->attempt_started_at && $run->attempt_started_at->diffInSeconds(now()) >= config('marketplaces.sync.execution_budget', 1500)) {
            throw new SyncException('budget', true);
        }
        $this->write($run, fn () => $run->update(['heartbeat_at' => now()]));
    }

    public function checkpoint(MarketplaceSyncRun $run, array $data): void
    {
        $this->heartbeat($run);
        $data = array_intersect_key($data, array_flip(['phase', 'cursor', 'has_next_page', 'parent_id', 'complete']));
        $this->write($run, fn () => $run->update(['checkpoint' => [...$data, 'scope' => $run->scope, 'attempt' => $run->attempt, 'strategy' => 'replay_from_start', 'reason' => 'Source cursors are diagnostic; a retry restarts pagination to avoid skipping mutable records.']]));
    }

    public function result(MarketplaceSyncRun $run, string $kind, string $id, string $outcome): void
    {
        $this->write($run, function () use ($run, $kind, $id, $outcome) {
            $rows = DB::table('marketplace_sync_results')->where('organization_id', $run->organization_id)->where('marketplace_account_id', $run->marketplace_account_id)->where('marketplace_sync_run_id', $run->id);
            $previous = (clone $rows)->where('kind', $kind)->where('external_id', $id)->first();
            if (! $previous) {
                DB::table('marketplace_sync_results')->insert(['organization_id' => $run->organization_id, 'marketplace_account_id' => $run->marketplace_account_id, 'marketplace_sync_run_id' => $run->id, 'kind' => $kind, 'external_id' => $id, 'outcome' => $outcome, 'attempt' => $run->attempt]);
            } elseif ($previous->outcome === 'skipped' && $outcome !== 'skipped') {
                DB::table('marketplace_sync_results')->where('id', $previous->id)->update(['outcome' => $outcome, 'attempt' => $run->attempt]);
            }
            $run->refresh();
            $progress = $run->attempt_progress ?? [];
            $progress[$kind] = ($progress[$kind] ?? 0) + 1;
            if ($outcome !== 'processed') {
                $progress[$outcome] = ($progress[$outcome] ?? 0) + 1;
            }
            $progress['processed'] = ($progress['variant'] ?? 0) + ($progress['inventory'] ?? 0);
            $changes = ['attempt_progress' => $progress, 'heartbeat_at' => now()];
            if (! $previous || ($previous->outcome === 'skipped' && $outcome !== 'skipped')) {
                if ($previous) {
                    $changes['skipped'] = max(0, $run->skipped - 1);
                }
                if ($kind === 'product') {
                    $changes['products_processed'] = $run->products_processed + 1;
                } elseif ($outcome === 'skipped') {
                    $changes['skipped'] = $run->skipped + 1;
                } else {
                    $counter = $kind === 'variant' ? 'variants_processed' : 'inventory_processed';
                    $changes[$counter] = $run->{$counter} + 1;
                    $changes['processed'] = $run->processed + 1;
                    if ($kind === 'variant' && in_array($outcome, ['created', 'updated'], true)) {
                        $changes[$outcome] = $run->{$outcome} + 1;
                    }
                }
            }
            $run->update($changes);
        });
    }

    public function error(MarketplaceSyncRun $run, string $reference, string $code): void
    {
        $code = (new SyncException($code))->reason;
        $this->write($run, function () use ($run, $reference, $code) {
            $run->refresh();
            $details = $run->details ?? [];
            if (count($details) < 100) {
                $details[] = ['reference' => mb_substr($reference, 0, 255), 'code' => $code, 'message' => SyncException::safeMessage($code)];
            }
            $run->update(['errors_count' => $run->errors_count + 1, 'skipped' => $run->skipped + 1, 'details' => $details, 'heartbeat_at' => now()]);
        });
    }
}
