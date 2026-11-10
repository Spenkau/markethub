<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\IndexRequest;
use App\Http\Requests\Api\V1\MarketplaceAccountRequest;
use App\Http\Resources\Api\V1\MarketplaceAccountResource;
use App\Models\MarketplaceAccount;
use App\Services\CatalogQuery;
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

    public function store(MarketplaceAccountRequest $request)
    {
        return (new MarketplaceAccountResource(MarketplaceAccount::create($request->validated())))->response()->setStatusCode(201);
    }

    public function show(MarketplaceAccount $marketplaceAccount): MarketplaceAccountResource
    {
        Gate::authorize('view', $marketplaceAccount);

        return new MarketplaceAccountResource($marketplaceAccount->loadCount('listings'));
    }

    public function update(MarketplaceAccountRequest $request, MarketplaceAccount $marketplaceAccount): MarketplaceAccountResource
    {
        $marketplaceAccount->update($request->validated());

        return new MarketplaceAccountResource($marketplaceAccount);
    }

    public function destroy(MarketplaceAccount $marketplaceAccount)
    {
        Gate::authorize('delete', $marketplaceAccount);
        $marketplaceAccount->delete();

        return response()->noContent();
    }
}
