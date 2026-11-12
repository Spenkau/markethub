<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\ShopifyConnectionRequest;
use App\Http\Requests\Api\V1\ShopifyIndexRequest;
use App\Http\Resources\Api\V1\MarketplaceAccountResource;
use App\Models\MarketplaceAccount;
use App\Models\MarketplaceSyncRun;
use App\Services\Marketplaces\CatalogMappingService;
use App\Services\Marketplaces\ConnectionService;
use App\Services\Shopify\ShopifyException;
use App\Services\Shopify\ShopifyImporter;
use App\Services\Shopify\SyncDispatcher;
use App\Support\OrganizationContext;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

class ShopifyController extends Controller
{
    private function authorizeAccount(MarketplaceAccount $account, string $ability = 'view'): void
    {
        Gate::authorize($ability, $account);
        abort_unless($account->marketplace === 'shopify', 404);
    }

    public function store(ShopifyConnectionRequest $request, ConnectionService $connections)
    {
        $data = $this->connectionData($request->validated());
        $data['marketplace'] = 'shopify';

        return (new MarketplaceAccountResource($connections->create(OrganizationContext::id(), $data)))->response()->setStatusCode(201);
    }

    private function connectionData(array $data): array
    {
        if (array_key_exists('shop_domain', $data)) {
            $data['configuration'] = ['shop_domain' => $data['shop_domain']];
            unset($data['shop_domain']);
        }
        foreach (['client_id', 'client_secret'] as $key) {
            if (array_key_exists($key, $data)) {
                $data['credentials'][$key] = $data[$key];
                unset($data[$key]);
            }
        }

        return $data;
    }

    public function show(MarketplaceAccount $marketplaceAccount)
    {
        $this->authorizeAccount($marketplaceAccount);

        return new MarketplaceAccountResource($marketplaceAccount->loadCount('listings'));
    }

    public function update(ShopifyConnectionRequest $request, MarketplaceAccount $marketplaceAccount, ConnectionService $connections)
    {
        $this->authorizeAccount($marketplaceAccount, 'update');

        return new MarketplaceAccountResource($connections->update($marketplaceAccount, $this->connectionData($request->validated())));
    }

    public function check(MarketplaceAccount $marketplaceAccount, ShopifyImporter $importer)
    {
        $this->authorizeAccount($marketplaceAccount, 'update');
        try {
            return response()->json(['data' => app(ConnectionService::class)->check($marketplaceAccount)]);
        } catch (ShopifyException $e) {
            $marketplaceAccount->forceFill(['api_status' => $e->reason, 'api_checked_at' => now()])->save();

            return response()->json(['message' => $e->getMessage()], 422);
        }
    }

    public function sync(Request $request, MarketplaceAccount $marketplaceAccount, SyncDispatcher $dispatcher)
    {
        $this->authorizeAccount($marketplaceAccount, 'update');
        $request->validate(['confirm_initial_import' => ['sometimes', 'boolean']]);
        abort_if(! $marketplaceAccount->initial_import_at && ! $request->boolean('confirm_initial_import'), 422, 'Подтвердите первоначальный импорт товаров и остатков.');
        $run = $dispatcher->enqueue($marketplaceAccount, 'manual', null, $request->boolean('confirm_initial_import'));

        return response()->json(['data' => $run], 202);
    }

    public function products(ShopifyIndexRequest $request, MarketplaceAccount $marketplaceAccount)
    {
        $this->authorizeAccount($marketplaceAccount);
        $query = DB::table('marketplace_listings as l')->leftJoin('products as p', 'p.id', '=', 'l.product_id')
            ->where('l.organization_id', OrganizationContext::id())->where('l.marketplace_account_id', $marketplaceAccount->id)
            ->select('l.*', 'p.name as local_name', 'p.sku as local_sku');
        if ($request->filled('search')) {
            $search = '%'.$request->validated('search').'%';
            $query->where(fn ($q) => $q->whereRaw("l.source_data->'product'->>'title' ILIKE ?", [$search])->orWhere('l.external_sku', 'ilike', $search));
        }
        if ($request->filled('status')) {
            $query->where('l.source_status', $request->validated('status'));
        }
        if ($request->validated('mapping') === 'unmapped') {
            $query->whereNull('l.product_id');
        }
        if ($request->validated('mapping') === 'mapped') {
            $query->whereNotNull('l.product_id');
        }

        return $this->page($query->orderBy('l.id'), $request, function ($row) use ($marketplaceAccount) {
            $source = json_decode($row->source_data, true);

            return [...(array) $row, 'source_data' => $source, 'admin_url' => 'https://'.$marketplaceAccount->shop_domain.'/admin/products/'.basename($source['product']['id'])];
        });
    }

    private function sourceListing(MarketplaceAccount $account, int $listing): object
    {
        $row = DB::table('marketplace_listings as l')->leftJoin('products as p', 'p.id', '=', 'l.product_id')
            ->where('l.organization_id', OrganizationContext::id())->where('l.marketplace_account_id', $account->id)->where('l.id', $listing)->whereNotNull('l.source_data')
            ->select('l.*', 'p.name as local_name', 'p.sku as local_sku')->first();
        abort_unless($row, 404);

        return $row;
    }

    public function product(MarketplaceAccount $marketplaceAccount, int $listing)
    {
        $this->authorizeAccount($marketplaceAccount);
        $row = $this->sourceListing($marketplaceAccount, $listing);
        $source = json_decode($row->source_data, true);
        $levels = DB::table('marketplace_inventory_levels as i')->join('marketplace_locations as loc', 'loc.id', '=', 'i.marketplace_location_id')->where('i.organization_id', OrganizationContext::id())->where('i.marketplace_listing_id', $row->id)->select('i.*', 'i.marketplace_location_id as shopify_location_id', 'loc.name as location_name')->orderBy('i.id')->get();

        return response()->json(['data' => [...(array) $row, 'source_data' => $source, 'admin_url' => 'https://'.$marketplaceAccount->shop_domain.'/admin/products/'.basename($source['product']['id']), 'levels' => $levels, 'active_run' => MarketplaceSyncRun::query()->where('organization_id', $marketplaceAccount->organization_id)->where('marketplace_account_id', $marketplaceAccount->id)->whereIn('status', ['queued', 'running'])->where(fn ($q) => $q->where('scope', 'account')->orWhere('target_listing_id', $listing))->first()]]);
    }

    public function refreshProduct(MarketplaceAccount $marketplaceAccount, int $listing, SyncDispatcher $dispatcher)
    {
        $this->authorizeAccount($marketplaceAccount, 'update');
        $this->sourceListing($marketplaceAccount, $listing);

        return response()->json(['data' => $dispatcher->enqueue($marketplaceAccount, 'manual', $listing)], 202);
    }

    public function map(Request $request, MarketplaceAccount $marketplaceAccount, int $listing)
    {
        $this->authorizeAccount($marketplaceAccount, 'update');
        $data = $request->validate(['product_id' => ['present', 'nullable', 'integer'], 'organization_id' => ['prohibited']]);
        app(CatalogMappingService::class)->map($marketplaceAccount, $listing, $data['product_id']);

        return response()->noContent();
    }

    public function inventory(ShopifyIndexRequest $request, MarketplaceAccount $marketplaceAccount)
    {
        $this->authorizeAccount($marketplaceAccount);
        $query = DB::table('marketplace_inventory_levels as i')->join('marketplace_locations as loc', 'loc.id', '=', 'i.marketplace_location_id')
            ->join('marketplace_listings as l', 'l.id', '=', 'i.marketplace_listing_id')->leftJoin('marketplace_products as p', 'p.id', '=', 'l.marketplace_product_id')
            ->where('i.organization_id', OrganizationContext::id())->where('i.marketplace_account_id', $marketplaceAccount->id)
            ->select('i.*', 'i.marketplace_location_id as shopify_location_id', 'loc.name as location_name', 'l.external_sku', 'l.product_id')->addSelect('p.title');
        if ($request->filled('location_id')) {
            $query->where('loc.id', $request->integer('location_id'));
        }
        if ($request->filled('product_id')) {
            $query->where('l.product_id', $request->integer('product_id'));
        }
        if ($request->filled('search')) {
            $search = '%'.$request->validated('search').'%';
            $query->where(fn ($q) => $q->where('l.external_sku', 'ilike', $search)->orWhere('p.title', 'ilike', $search)->orWhere('l.barcode', 'ilike', $search));
        }

        return $this->page($query->orderBy('i.id'), $request);
    }

    public function locations(MarketplaceAccount $marketplaceAccount)
    {
        $this->authorizeAccount($marketplaceAccount);

        return response()->json(['data' => DB::table('marketplace_locations')->where('organization_id', OrganizationContext::id())->where('marketplace_account_id', $marketplaceAccount->id)->orderBy('id')->get()]);
    }

    public function runs(ShopifyIndexRequest $request, MarketplaceAccount $marketplaceAccount)
    {
        $this->authorizeAccount($marketplaceAccount);

        return app(MarketplaceSyncController::class)->history($request, $marketplaceAccount, true);
    }

    public function run(MarketplaceAccount $marketplaceAccount, string $run)
    {
        $this->authorizeAccount($marketplaceAccount);

        return app(MarketplaceSyncController::class)->show($marketplaceAccount, $run, true);
    }

    private function page($query, Request $request, ?callable $transform = null)
    {
        $page = $query->paginate($request->integer('per_page', 20))->withQueryString();
        if ($transform) {
            $page->through($transform);
        }

        return response()->json(['data' => $page->items(), 'meta' => ['current_page' => $page->currentPage(), 'last_page' => $page->lastPage(), 'per_page' => $page->perPage(), 'total' => $page->total()],
            'links' => ['first' => $page->url(1), 'last' => $page->url($page->lastPage()), 'prev' => $page->previousPageUrl(), 'next' => $page->nextPageUrl()]]);
    }
}
