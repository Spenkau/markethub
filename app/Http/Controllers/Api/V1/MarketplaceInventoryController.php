<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\IndexRequest;
use App\Http\Resources\Api\V1\MarketplaceListingResource;
use App\Models\MarketplaceAccount;
use App\Models\MarketplaceListing;
use App\Models\MarketplaceLocation;
use App\Models\Warehouse;
use App\Services\CatalogQuery;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

class MarketplaceInventoryController extends Controller
{
    public function index(IndexRequest $request, CatalogQuery $catalog)
    {
        Gate::authorize('viewAny', MarketplaceListing::class);

        return MarketplaceListingResource::collection($catalog->listingQuery($request, true)->paginate($request->integer('per_page', 20))->withQueryString());
    }

    public function inventory(IndexRequest $request, MarketplaceAccount $marketplaceAccount, CatalogQuery $catalog)
    {
        Gate::authorize('view', $marketplaceAccount);
        $query = $catalog->listingQuery($request, true)->where('marketplace_listings.marketplace_account_id', $marketplaceAccount->id);

        return MarketplaceListingResource::collection($query->paginate($request->integer('per_page', 20))->withQueryString());
    }

    public function locations(IndexRequest $request, MarketplaceAccount $marketplaceAccount)
    {
        Gate::authorize('view', $marketplaceAccount);

        return response()->json(['data' => MarketplaceLocation::query()->where('marketplace_account_id', $marketplaceAccount->id)->with('warehouse:id,name')->orderBy('id')->get()->map(fn ($l) => $this->locationData($l))]);
    }

    private function locationData(MarketplaceLocation $l): array
    {
        return ['id' => $l->id, 'marketplace_account_id' => $l->marketplace_account_id, 'external_id' => $l->external_id, 'name' => $l->name, 'is_active' => $l->is_active, 'warehouse_id' => $l->warehouse_id, 'warehouse' => $l->warehouse?->only(['id', 'name']), 'source_synced_at' => $l->source_synced_at?->toISOString()];
    }

    public function mapping(Request $request, MarketplaceLocation $marketplaceLocation)
    {
        $account = $marketplaceLocation->marketplaceAccount;
        Gate::authorize('update', $account);
        $data = $request->validate(['warehouse_id' => ['present', 'nullable', 'integer', 'min:1'], 'organization_id' => ['prohibited'], 'marketplace_account_id' => ['prohibited']]);
        DB::transaction(function () use ($marketplaceLocation, $account, $data) {
            MarketplaceAccount::query()->whereKey($account->id)->lockForUpdate()->firstOrFail();
            $location = MarketplaceLocation::query()->where('marketplace_account_id', $account->id)->lockForUpdate()->findOrFail($marketplaceLocation->id);
            if ($data['warehouse_id'] !== null) {
                $warehouse = Warehouse::query()->findOrFail($data['warehouse_id']);
                Gate::authorize('view', $warehouse);
            }
            $location->warehouse_id = $data['warehouse_id'];
            $location->save();
        });

        return response()->json(['data' => $this->locationData($marketplaceLocation->fresh()->load('warehouse:id,name'))]);
    }
}
