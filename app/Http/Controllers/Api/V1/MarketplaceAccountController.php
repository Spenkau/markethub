<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\IndexRequest;
use App\Http\Requests\Api\V1\MarketplaceAccountRequest;
use App\Http\Resources\Api\V1\MarketplaceAccountResource;
use App\Models\MarketplaceAccount;
use App\Services\CatalogQuery;
use App\Services\Marketplaces\ConnectionService;
use App\Services\Marketplaces\ProviderRegistry;
use App\Support\OrganizationContext;
use Illuminate\Support\Facades\Gate;

class MarketplaceAccountController extends Controller
{
    public function index(IndexRequest $request, CatalogQuery $catalog)
    {
        Gate::authorize('viewAny', MarketplaceAccount::class);
        $query = MarketplaceAccount::query()->withCount('listings');
        foreach (['marketplace', 'status'] as $field) {
            if ($request->has($field)) {
                $query->where($field, $request->validated($field));
            }
        }
        if ($request->has('is_demo')) {
            $query->where('is_demo', $request->boolean('is_demo'));
        }
        if ($request->filled('search')) {
            $query->where('name', 'ilike', '%'.$request->validated('search').'%');
        }

        return MarketplaceAccountResource::collection($catalog->paginate($query, $request, ['id' => 'id', 'name' => 'name', 'created_at' => 'created_at']));
    }

    public function store(MarketplaceAccountRequest $request, ConnectionService $connections)
    {
        return (new MarketplaceAccountResource($connections->create(OrganizationContext::id(), $request->all())))->response()->setStatusCode(201);
    }

    public function show(MarketplaceAccount $marketplaceAccount): MarketplaceAccountResource
    {
        Gate::authorize('view', $marketplaceAccount);

        return new MarketplaceAccountResource($marketplaceAccount->loadCount('listings'));
    }

    public function update(MarketplaceAccountRequest $request, MarketplaceAccount $marketplaceAccount, ConnectionService $connections): MarketplaceAccountResource
    {
        $marketplaceAccount = $connections->update($marketplaceAccount, $request->all());

        return new MarketplaceAccountResource($marketplaceAccount);
    }

    public function providers(ProviderRegistry $providers)
    {
        Gate::authorize('viewAny', MarketplaceAccount::class);

        return response()->json(['data' => $providers->definitions()]);
    }

    public function check(MarketplaceAccount $marketplaceAccount, ConnectionService $connections)
    {
        return response()->json(['data' => $connections->check($marketplaceAccount)]);
    }

    public function disconnect(MarketplaceAccount $marketplaceAccount, ConnectionService $connections)
    {
        return new MarketplaceAccountResource($connections->disconnect($marketplaceAccount));
    }

    public function destroy(MarketplaceAccount $marketplaceAccount)
    {
        Gate::authorize('delete', $marketplaceAccount);
        abort_if(app(ProviderRegistry::class)->get($marketplaceAccount->marketplace)->definition()['connection_type'] !== 'demo', 422, 'Отключите подключение вместо удаления истории.');
        $marketplaceAccount->delete();

        return response()->noContent();
    }
}
