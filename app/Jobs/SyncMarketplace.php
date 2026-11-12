<?php

namespace App\Jobs;

use App\Models\MarketplaceAccount;
use App\Models\MarketplaceSyncRun;
use App\Services\Marketplaces\ProviderRegistry;
use App\Services\Marketplaces\SyncDispatcher;
use App\Services\Marketplaces\SyncException;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class SyncMarketplace implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public int $timeout = 1800;

    public bool $failOnTimeout = true;

    public function __construct(public string $runId) {}

    public function backoff(): array
    {
        return [60, 300, 900];
    }

    public function handle(): void
    {
        $run = MarketplaceSyncRun::withoutGlobalScopes()->find($this->runId);
        if (! $run || ! in_array($run->status, ['queued', 'running'], true) || ! SyncDispatcher::lock($run->marketplace_account_id)) {
            return;
        }
        $accountId = $run->marketplace_account_id;
        $lease = null;
        try {
            // A duplicate delivery may have waited until another worker completed the run.
            $run = MarketplaceSyncRun::withoutGlobalScopes()->where('organization_id', $run->organization_id)->findOrFail($this->runId);
            if (! in_array($run->status, ['queued', 'running'], true)) {
                return;
            }
            if ($run->next_dispatch_at && $run->next_dispatch_at->isFuture()) {
                return;
            }
            if ($run->attempt >= config('marketplaces.sync.max_attempts', 3)) {
                app(SyncDispatcher::class)->failure($run, 'attempts_exhausted', false);

                return;
            }
            $account = MarketplaceAccount::withoutGlobalScopes()->where('organization_id', $run->organization_id)->findOrFail($run->marketplace_account_id);
            $provider = app(ProviderRegistry::class)->get($account->marketplace);
            $caps = $provider->definition()['capabilities'];
            $importer = $provider->importer();
            if ($account->status !== 'active' || $account->marketplace !== $run->provider || ! in_array('sync', $caps, true) || ! $importer) {
                throw new SyncException('disabled');
            }
            if (($run->sync_products && ! in_array('products', $caps, true)) || ($run->sync_inventory && ! in_array('inventory', $caps, true))) {
                throw new SyncException('disabled');
            }
            if (($run->scope === 'variant' && ! in_array('refresh_listing', $caps, true)) || ($run->scope === 'product' && ! in_array('refresh_product', $caps, true))) {
                throw new SyncException('disabled');
            }
            $run->update(['status' => 'running', 'attempt' => $run->attempt + 1, 'started_at' => $run->started_at ?? now(), 'attempt_started_at' => now(), 'heartbeat_at' => now(), 'finished_at' => null, 'next_dispatch_at' => null,
                'reconcile_token' => (string) Str::uuid(), 'error_message' => null, 'errors_count' => 0, 'skipped' => DB::table('marketplace_sync_results')->where('organization_id', $run->organization_id)->where('marketplace_sync_run_id', $run->id)->where('outcome', 'skipped')->count(), 'details' => [], 'attempt_progress' => []]);
            $lease = $run->reconcile_token;
            $run->bindExecutionLease($lease);
            $run->checkpoint(['phase' => 'start', 'complete' => false]);
            $importer($account, $run);
            $run = MarketplaceSyncRun::withoutGlobalScopes()->where('organization_id', $run->organization_id)->whereKey($run->id)->where('reconcile_token', $lease)->where('status', 'running')->first();
            if (! $run) {
                return;
            }
            $run->bindExecutionLease($lease);
            $success = $run->errors_count === 0;
            $run->guardedWrite(function () use ($run, $account, $success) {
                $run->update(['status' => $success ? 'completed' : ($run->processed ? 'partial' : 'failed'), 'finished_at' => now(), 'heartbeat_at' => now(), 'error_message' => $success ? null : 'Синхронизация завершилась с ошибками. Проверьте детали запуска.']);
                if ($run->scope !== 'account') {
                    return;
                }
                $account = MarketplaceAccount::withoutGlobalScopes()->where('organization_id', $run->organization_id)->lockForUpdate()->findOrFail($account->id);
                if ($success) {
                    $account->last_synced_at = now();
                    $account->initial_import_at ??= now();
                    if ($run->sync_products) {
                        $account->last_products_synced_at = $run->started_at;
                        if ($run->full_reconcile) {
                            $account->last_full_sync_at = now();
                        }
                    }
                }
                $account->next_sync_at = SyncDispatcher::next($account);
                $account->save();
            });
        } catch (SyncException $e) {
            if ($e->reason !== 'stale_attempt') {
                app(SyncDispatcher::class)->failure($run, $e->reason, $e->retryable, $lease);
            }
        } catch (\Throwable) {
            app(SyncDispatcher::class)->failure($run, 'provider_error', false, $lease);
        } finally {
            SyncDispatcher::unlock($accountId);
        }
    }

    public function failed(?\Throwable $exception): void
    {
        $run = MarketplaceSyncRun::withoutGlobalScopes()->find($this->runId);
        if (! $run || ! SyncDispatcher::lock($run->marketplace_account_id)) {
            return;
        }
        try {
            $run->refresh();
            if (in_array($run->status, ['queued', 'running'], true)) {
                app(SyncDispatcher::class)->failure($run, 'worker_lost', true);
            }
        } finally {
            SyncDispatcher::unlock($run->marketplace_account_id);
        }
    }
}
