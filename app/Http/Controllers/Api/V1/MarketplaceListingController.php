<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\IndexRequest;
use App\Http\Requests\Api\V1\MarketplaceListingRequest;
use App\Http\Resources\Api\V1\MarketplaceListingResource;
use App\Http\Resources\Api\V1\ProductResource;
use App\Models\MarketplaceListing;
use App\Services\CatalogQuery;
use App\Services\Marketplaces\CatalogMappingService;
use Illuminate\Http\Request;
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
        return (new MarketplaceListingResource(MarketplaceListing::create($request->validated())->load('product', 'marketplaceAccount', 'marketplaceProduct')))->response()->setStatusCode(201);
    }

    public function show(MarketplaceListing $marketplaceListing): MarketplaceListingResource
    {
        Gate::authorize('view', $marketplaceListing);

        return new MarketplaceListingResource($marketplaceListing->load('product', 'marketplaceAccount', 'marketplaceProduct'));
    }

    public function update(MarketplaceListingRequest $request, MarketplaceListing $marketplaceListing): MarketplaceListingResource
    {
        $marketplaceListing->update($request->validated());

        return new MarketplaceListingResource($marketplaceListing->load('product', 'marketplaceAccount', 'marketplaceProduct'));
    }

    public function mapping(Request $request, MarketplaceListing $marketplaceListing, CatalogMappingService $mapping)
    {
        Gate::authorize('update', $marketplaceListing);
        $data = $request->validate(['product_id' => ['present', 'nullable', 'integer'], 'organization_id' => ['prohibited'], 'marketplace_account_id' => ['prohibited']]);
        $mapping->map($marketplaceListing->marketplaceAccount, $marketplaceListing->id, $data['product_id']);

        return response()->noContent();
    }

    public function createLocalProduct(Request $request, MarketplaceListing $marketplaceListing, CatalogMappingService $mapping)
    {
        Gate::authorize('update', $marketplaceListing);
        $data = $request->validate(['sku' => ['required', 'string', 'max:100'], 'name' => ['sometimes', 'string', 'max:255'], 'organization_id' => ['prohibited'], 'product_id' => ['prohibited'], 'marketplace_account_id' => ['prohibited']]);
        $product = $mapping->createLocalProduct($marketplaceListing->marketplaceAccount, $marketplaceListing->id, $data);

        return (new ProductResource($product))->response()->setStatusCode(201);
    }

    public function destroy(MarketplaceListing $marketplaceListing)
    {
        Gate::authorize('delete', $marketplaceListing);
        abort_if($marketplaceListing->origin === 'imported', 422, 'Размещения Shopify сохраняются как история источника.');
        $marketplaceListing->delete();

        return response()->noContent();
    }
}
