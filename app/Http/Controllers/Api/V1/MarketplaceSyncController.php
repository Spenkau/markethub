<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\MarketplaceAccount;
use App\Models\MarketplaceListing;
use App\Models\MarketplaceSyncRun;
use App\Services\Marketplaces\SyncDispatcher;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;

class MarketplaceSyncController extends Controller
{
    public function sync(Request $request, MarketplaceAccount $marketplaceAccount, SyncDispatcher $dispatcher)
    {
        Gate::authorize('update', $marketplaceAccount);
        $data = $request->validate(['confirm_initial_import' => ['sometimes', 'boolean'], 'sync_products' => ['sometimes', 'boolean'], 'sync_inventory' => ['sometimes', 'boolean'],
            'full_reconcile' => ['sometimes', 'boolean'], 'scope' => ['sometimes', Rule::in(['account', 'product'])], 'target_product_id' => ['sometimes', 'integer', 'min:1'],
            'organization_id' => ['prohibited'], 'marketplace_account_id' => ['prohibited'], 'target_listing_id' => ['prohibited']]);
        $run = $dispatcher->enqueue($marketplaceAccount, 'manual', null, $request->boolean('confirm_initial_import'), $data);

        return response()->json(['data' => $run], 202);
    }

    public function refresh(MarketplaceListing $marketplaceListing, SyncDispatcher $dispatcher)
    {
        Gate::authorize('update', $marketplaceListing);
        $run = $dispatcher->enqueue($marketplaceListing->marketplaceAccount, 'manual', $marketplaceListing->id);

        return response()->json(['data' => $run], 202);
    }

    public function history(Request $request, MarketplaceAccount $marketplaceAccount, bool $legacy = false)
    {
        Gate::authorize('view', $marketplaceAccount);
        $request->validate(['page' => ['sometimes', 'integer', 'min:1'], 'per_page' => ['sometimes', 'integer', 'min:1', 'max:100'],
            'status' => ['sometimes', Rule::in(['queued', 'running', 'completed', 'partial', 'failed'])],
            'trigger' => ['sometimes', Rule::in(['manual', 'scheduled', 'initial', 'retry', 'automatic'])],
            'scope' => ['sometimes', Rule::in(['account', 'product', 'variant'])],
            'date_from' => ['sometimes', 'date_format:Y-m-d'], 'date_to' => ['sometimes', 'date_format:Y-m-d', 'after_or_equal:date_from']]);
        $query = $this->runs($marketplaceAccount);
        foreach (['status', 'scope'] as $key) {
            if ($request->filled($key)) {
                $query->where($key, $request->input($key));
            }
        }
        if ($request->filled('trigger')) {
            $trigger = $request->input('trigger');
            $query->whereIn('trigger', $legacy ? ($trigger === 'automatic' ? ['automatic', 'scheduled'] : ['manual', 'initial', 'retry']) : ($trigger === 'scheduled' ? ['scheduled', 'automatic'] : [$trigger]));
        }
        if ($request->filled('date_from')) {
            $query->where('created_at', '>=', $request->input('date_from').'T00:00:00Z');
        }
        if ($request->filled('date_to')) {
            $query->where('created_at', '<', Carbon::parse($request->input('date_to'), 'UTC')->addDay()->toIso8601String());
        }
        $page = $query->orderByDesc('created_at')->orderByDesc('id')->paginate($request->integer('per_page', 20))->withQueryString();
        $page->through(fn ($run) => ['account_name' => $marketplaceAccount->name, ...$this->data($run, $legacy)]);

        return response()->json(['data' => $page->items(), 'meta' => ['current_page' => $page->currentPage(), 'last_page' => $page->lastPage(), 'per_page' => $page->perPage(), 'total' => $page->total()],
            'links' => ['first' => $page->url(1), 'last' => $page->url($page->lastPage()), 'prev' => $page->previousPageUrl(), 'next' => $page->nextPageUrl()]]);
    }

    public function show(MarketplaceAccount $marketplaceAccount, string $run, bool $legacy = false)
    {
        Gate::authorize('view', $marketplaceAccount);

        return response()->json(['data' => ['account_name' => $marketplaceAccount->name, ...$this->data($this->runs($marketplaceAccount)->findOrFail($run), $legacy)]]);
    }

    public function status(MarketplaceAccount $marketplaceAccount)
    {
        Gate::authorize('view', $marketplaceAccount);

        return response()->json(['data' => ['account_id' => $marketplaceAccount->id, 'account_name' => $marketplaceAccount->name, 'provider' => $marketplaceAccount->marketplace,
            'last_success_at' => $marketplaceAccount->last_synced_at?->toISOString(), 'next_sync_at' => $marketplaceAccount->next_sync_at?->toISOString(),
            'sync_frequency' => $marketplaceAccount->sync_frequency, 'active_run' => $this->runs($marketplaceAccount)->whereIn('status', ['queued', 'running'])->first(),
            'latest_run' => $this->runs($marketplaceAccount)->orderByDesc('created_at')->orderByDesc('id')->first()]]);
    }

    private function runs(MarketplaceAccount $account)
    {
        return MarketplaceSyncRun::query()->where('organization_id', $account->organization_id)->where('marketplace_account_id', $account->id);
    }

    private function data(MarketplaceSyncRun $run, bool $legacy): array
    {
        $data = $run->toArray();
        if ($legacy) {
            $data['trigger'] = in_array($run->trigger, ['scheduled', 'automatic'], true) ? 'automatic' : 'manual';
        }
        if ($run->scope !== 'account') {
            $data['full_reconcile'] = false;
        }

        return $data;
    }
}
