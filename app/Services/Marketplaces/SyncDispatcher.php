<?php

namespace App\Services\Marketplaces;

use App\Jobs\SyncMarketplace;
use App\Models\MarketplaceAccount;
use App\Models\MarketplaceListing;
use App\Models\MarketplaceProduct;
use App\Models\MarketplaceSyncRun;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;

class SyncDispatcher
{
    public function __construct(private ProviderRegistry $providers) {}

    public static function lockKey(int $account): string
    {
        return 'marketplace-sync:'.$account;
    }

    public static function lock(int $account): bool
    {
        return (bool) DB::selectOne('SELECT pg_try_advisory_lock(hashtextextended(CAST(? AS text),0)) locked', [self::lockKey($account)])->locked;
    }

    public static function unlock(int $account): void
    {
        DB::select('SELECT pg_advisory_unlock(hashtextextended(CAST(? AS text),0))', [self::lockKey($account)]);
    }

    public function enqueue(MarketplaceAccount $account, string $trigger = 'manual', ?int $listingId = null, bool $confirmInitial = false, array $options = []): MarketplaceSyncRun
    {
        if (auth()->check()) {
            Gate::authorize('update', $account);
        }
        abort_if(app()->isDownForMaintenance() && ! app()->runningUnitTests(), 503, 'Синхронизация временно приостановлена.');

        $run = DB::transaction(function () use ($account, $trigger, $listingId, $confirmInitial, $options) {
            $account = MarketplaceAccount::withoutGlobalScopes()->where('organization_id', $account->organization_id)->lockForUpdate()->findOrFail($account->id);
            $provider = $this->providers->get($account->marketplace);
            $caps = $provider->definition()['capabilities'];
            abort_unless($account->status === 'active' && in_array('sync', $caps, true) && $provider->importer(), 422, 'Подключение отключено или провайдер не поддерживает синхронизацию.');
            $scope = $listingId ? 'variant' : ($options['scope'] ?? 'account');
            $productId = isset($options['target_product_id']) ? (int) $options['target_product_id'] : null;
            abort_unless(in_array($scope, ['account', 'variant', 'product'], true), 422, 'Неизвестная область синхронизации.');
            if ($scope === 'variant') {
                abort_unless($listingId && in_array('refresh_listing', $caps, true), 422, 'Точечное обновление варианта не поддерживается.');
                MarketplaceListing::withoutGlobalScopes()->where('organization_id', $account->organization_id)->where('marketplace_account_id', $account->id)->findOrFail($listingId);
            } elseif ($scope === 'product') {
                abort_unless($productId && in_array('refresh_product', $caps, true), 422, 'Точечное обновление родителя не поддерживается.');
                MarketplaceProduct::withoutGlobalScopes()->where('organization_id', $account->organization_id)->where('marketplace_account_id', $account->id)->findOrFail($productId);
            } else {
                abort_if($productId, 422, 'Цель не допускается для полного подключения.');
            }
            $products = $options['sync_products'] ?? $account->sync_products;
            $inventory = $options['sync_inventory'] ?? $account->sync_inventory;
            abort_unless($products || $inventory, 422, 'Включите хотя бы один тип данных.');
            abort_if(($products && ! in_array('products', $caps, true)) || ($inventory && ! in_array('inventory', $caps, true)), 422, 'Выбранные типы данных не поддерживаются провайдером.');
            abort_if($scope === 'account' && ! $account->initial_import_at && ! $confirmInitial, 422, 'Подтвердите первоначальный импорт.');
            abort_unless(in_array($trigger, ['manual', 'scheduled', 'initial', 'retry', 'automatic'], true), 422, 'Неизвестный источник запуска.');
            $existing = MarketplaceSyncRun::withoutGlobalScopes()->where('organization_id', $account->organization_id)->where('marketplace_account_id', $account->id)->whereIn('status', ['queued', 'running'])->first();
            if ($existing) {
                abort_if($existing->scope !== $scope || $existing->target_listing_id !== $listingId || $existing->target_product_id !== $productId, 409, 'Дождитесь завершения текущей синхронизации подключения.');

                return $existing;
            }
            $run = MarketplaceSyncRun::create([
                'id' => (string) Str::uuid(),
                'organization_id' => $account->organization_id,
                'marketplace_account_id' => $account->id,
                'provider' => $account->marketplace,
                'trigger' => ! $account->initial_import_at && $scope === 'account'
                    ? 'initial'
                    : ($trigger === 'automatic' ? 'scheduled' : $trigger),
                'scope' => $scope,
                'target_listing_id' => $listingId,
                'target_product_id' => $productId,
                'status' => 'queued',
                'dispatch_state' => 'pending',
                'sync_products' => $products,
                'sync_inventory' => $inventory,
                'full_reconcile' => $scope === 'account'
                    && $products
                    && (($options['full_reconcile'] ?? false)
                        || ! $account->last_full_sync_at
                        || $account->last_full_sync_at->lt(now()->subDay())
                        || ! $account->last_products_synced_at
                    ),
            ]);
            DB::afterCommit(fn () => $this->publish($run->id));

            return $run->fresh();
        });

        return $run->fresh();
    }

    /** A PostgreSQL dispatch record is not an atomic PostgreSQL/Redis transaction. */
    public function publish(string $id): bool
    {
        $claimed = DB::transaction(function () use ($id) {
            $run = MarketplaceSyncRun::withoutGlobalScopes()->lockForUpdate()->find($id);
            if (! $run || $run->status !== 'queued' || $run->dispatch_state !== 'pending' || ($run->next_dispatch_at && $run->next_dispatch_at->isFuture())) {
                return null;
            }
            $run->update(['dispatch_state' => 'publishing', 'dispatch_lease_at' => now(), 'dispatch_attempts' => $run->dispatch_attempts + 1]);

            return $run;
        });
        if (! $claimed) {
            return false;
        }
        try {
            $connection = config('marketplaces.sync.connection', config('queue.default'));
            if ($connection === 'sync' && ! app()->runningUnitTests()) {
                throw new SyncException('dispatch');
            }
            Queue::connection($connection)->push(new SyncMarketplace($id), '', config('marketplaces.sync.queue', 'marketplaces'));
            MarketplaceSyncRun::withoutGlobalScopes()->where('organization_id', $claimed->organization_id)->whereKey($id)->where('dispatch_state', 'publishing')->update(['dispatch_state' => 'published', 'dispatched_at' => now()->toIso8601String(), 'dispatch_lease_at' => null]);

            return true;
        } catch (\Throwable) {
            // Never log exception text: queue connection strings can contain credentials.
            DB::transaction(function () use ($claimed, $id) {
                $run = MarketplaceSyncRun::withoutGlobalScopes()->where('organization_id', $claimed->organization_id)->lockForUpdate()->find($id);
                if (! $run || $run->dispatch_state !== 'publishing') {
                    return;
                }
                $diagnostics = $run->attempt_errors ?? [];
                $diagnostics[] = ['stage' => 'dispatch', 'attempt' => $run->attempt, 'dispatch_attempt' => $run->dispatch_attempts, 'code' => 'dispatch', 'message' => SyncException::safeMessage('dispatch'), 'finished_at' => now()->toIso8601String()];
                $run->update(['dispatch_state' => 'pending', 'dispatch_lease_at' => null, 'next_dispatch_at' => now()->addSeconds(60), 'attempt_errors' => array_slice($diagnostics, -10)]);
            });

            return false;
        }
    }

    public function recover(): int
    {
        if (app()->isDownForMaintenance() && ! app()->runningUnitTests()) {
            return 0;
        }
        $recovered = 0;
        MarketplaceSyncRun::withoutGlobalScopes()->whereIn('status', ['queued', 'running'])->orderBy('id')->chunkById(100, function ($runs) use (&$recovered) {
            foreach ($runs as $candidate) {
                if (! self::lock($candidate->marketplace_account_id)) {
                    continue;
                } // Live worker wins, even with an old heartbeat.
                try {
                    $id = DB::transaction(function () use ($candidate) {
                        $run = MarketplaceSyncRun::withoutGlobalScopes()->where('organization_id', $candidate->organization_id)->lockForUpdate()->find($candidate->id);
                        if (! $run || ! in_array($run->status, ['queued', 'running'], true)) {
                            return null;
                        }
                        if ($run->status === 'running') {
                            $last = $run->heartbeat_at ?? $run->attempt_started_at ?? $run->started_at ?? $run->updated_at;
                            if ($last->gt(now()->subSeconds(config('marketplaces.sync.heartbeat_stale', 300)))) {
                                return null;
                            }
                            $this->failure($run, 'worker_lost', true);
                        } elseif ($run->dispatch_state === 'publishing') {
                            if ($run->dispatch_lease_at?->gt(now()->subSeconds(90))) {
                                return null;
                            }
                            $run->update(['dispatch_state' => 'pending', 'dispatch_lease_at' => null]);
                        } elseif ($run->dispatch_state === 'published') {
                            $last = $run->dispatched_at ?? $run->updated_at;
                            if ($last->gt(now()->subSeconds(config('marketplaces.sync.queued_stale', 300)))) {
                                return null;
                            }
                            $run->update(['dispatch_state' => 'pending']);
                        }
                        if ($run->status !== 'queued' || ($run->next_dispatch_at && $run->next_dispatch_at->isFuture())) {
                            return null;
                        }
                        if ($run->dispatch_attempts >= config('marketplaces.sync.max_dispatches', 12)) {
                            $this->failure($run, 'dispatch', false);

                            return null;
                        }

                        return $run->id;
                    });
                } finally {
                    self::unlock($candidate->marketplace_account_id);
                }
                if ($id && $this->publish($id)) {
                    $recovered++;
                }
            }
        });

        return $recovered;
    }

    public function schedule(): int
    {
        if (app()->isDownForMaintenance() && ! app()->runningUnitTests()) {
            return 0;
        }
        $this->recover();
        $count = 0;
        MarketplaceAccount::withoutGlobalScopes()->where('status', 'active')->whereNotNull('initial_import_at')->whereIn('sync_frequency', ['hourly', 'daily'])
            ->where(fn ($q) => $q->where('sync_products', true)->orWhere('sync_inventory', true))->where('next_sync_at', '<=', now()->toIso8601String())->orderBy('id')->chunkById(100, function ($accounts) use (&$count) {
                foreach ($accounts as $account) {
                    if (! in_array($account->marketplace, $this->providers->codes(), true)) {
                        continue;
                    }
                    $provider = $this->providers->get($account->marketplace);
                    if (! in_array('sync', $provider->definition()['capabilities'], true) || ! $provider->importer()) {
                        continue;
                    }
                    DB::transaction(function () use ($account, &$count) {
                        $fresh = MarketplaceAccount::withoutGlobalScopes()->where('organization_id', $account->organization_id)->lockForUpdate()->find($account->id);
                        if (! $fresh || $fresh->status !== 'active' || $fresh->sync_frequency === 'manual' || ! $fresh->next_sync_at || $fresh->next_sync_at->isFuture() || (! $fresh->sync_products && ! $fresh->sync_inventory)) {
                            return;
                        }
                        if (MarketplaceSyncRun::withoutGlobalScopes()->where('organization_id', $fresh->organization_id)->where('marketplace_account_id', $fresh->id)->whereIn('status', ['queued', 'running'])->exists()) {
                            return;
                        }
                        $caps = $this->providers->get($fresh->marketplace)->definition()['capabilities'];
                        if (($fresh->sync_products && ! in_array('products', $caps, true)) || ($fresh->sync_inventory && ! in_array('inventory', $caps, true))) {
                            return;
                        }
                        $this->enqueue($fresh, 'scheduled', null, true);
                        $count++;
                    });
                }
            });

        return $count;
    }

    public static function next(MarketplaceAccount $account): mixed
    {
        if ($account->status !== 'active' || ! $account->initial_import_at || (! $account->sync_products && ! $account->sync_inventory)) {
            return null;
        }

        return match ($account->sync_frequency) {
            'hourly' => now()->addHour(),
            'daily' => now()->addDay(),
            default => null
        };
    }

    public function failure(MarketplaceSyncRun $run, string $reason, bool $retryable, ?string $expectedLease = null): void
    {
        DB::transaction(function () use ($run, $reason, $retryable, $expectedLease) {
            $expectedLease ??= $run->executionLease();
            $current = MarketplaceSyncRun::withoutGlobalScopes()->where('organization_id', $run->organization_id)->where('marketplace_account_id', $run->marketplace_account_id)->whereKey($run->id)->lockForUpdate()->first();
            if (! $current) {
                return;
            }
            if ($expectedLease && $current->reconcile_token !== $expectedLease) {
                return;
            }
            $run->setRawAttributes($current->getAttributes(), true);
            if (! in_array($run->status, ['queued', 'running'], true)) {
                return;
            }
            $errors = $run->attempt_errors ?? [];
            $errors[] = ['attempt' => $run->attempt, 'code' => $reason, 'message' => SyncException::safeMessage($reason), 'progress' => $run->attempt_progress ?? [], 'checkpoint' => $run->checkpoint, 'finished_at' => now()->toIso8601String()];
            $retry = $retryable && $run->attempt < config('marketplaces.sync.max_attempts', 3);
            $run->update(['status' => $retry ? 'queued' : ($run->processed ? 'partial' : 'failed'), 'dispatch_state' => 'pending', 'dispatch_lease_at' => null,
                'next_dispatch_at' => $retry ? now()->addSeconds($reason === 'worker_lost' ? 0 : ($run->attempt <= 1 ? 60 : 300)) : null,
                'attempt_errors' => array_slice($errors, -10), 'error_message' => $retry ? null : SyncException::safeMessage($reason), 'errors_count' => $retry ? $run->errors_count : $run->errors_count + 1, 'finished_at' => $retry ? null : now()]);
            if (! $retry && $run->scope === 'account') {
                $account = MarketplaceAccount::withoutGlobalScopes()->where('organization_id', $run->organization_id)->find($run->marketplace_account_id);
                if ($account) {
                    $account->forceFill(['next_sync_at' => $account->initial_import_at && $account->sync_frequency !== 'manual' && $account->status === 'active' ? now()->addHour() : null])->save();
                }
            }
        });
    }
}
