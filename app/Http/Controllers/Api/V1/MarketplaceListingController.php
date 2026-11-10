<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\IndexRequest;
use App\Http\Requests\Api\V1\MarketplaceListingRequest;
use App\Http\Resources\Api\V1\MarketplaceListingResource;
use App\Models\MarketplaceListing;
use App\Services\CatalogQuery;
use Illuminate\Support\Facades\Gate;

class MarketplaceListingController extends Controller
{
    public function index(IndexRequest $request, CatalogQuery $catalog)
    {
        Gate::authorize('viewAny', MarketplaceListing::class);

        return MarketplaceListingResource::collection($catalog->listings($request));
    }

    public function store(MarketplaceListingRequest $request)
    {
        return (new MarketplaceListingResource(MarketplaceListing::create($request->validated())->load('product', 'marketplaceAccount')))->response()->setStatusCode(201);
    }

    public function show(MarketplaceListing $marketplaceListing): MarketplaceListingResource
    {
        Gate::authorize('view', $marketplaceListing);

        return new MarketplaceListingResource($marketplaceListing->load('product', 'marketplaceAccount'));
    }

    public function update(MarketplaceListingRequest $request, MarketplaceListing $marketplaceListing): MarketplaceListingResource
    {
        $marketplaceListing->update($request->validated());

        return new MarketplaceListingResource($marketplaceListing->load('product', 'marketplaceAccount'));
    }

    public function destroy(MarketplaceListing $marketplaceListing)
    {
        Gate::authorize('delete', $marketplaceListing);
        $marketplaceListing->delete();

        return response()->noContent();
    }
}
