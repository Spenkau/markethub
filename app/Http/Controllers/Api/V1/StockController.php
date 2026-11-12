<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\IndexRequest;
use App\Http\Resources\Api\V1\MarketplaceListingResource;
use App\Models\MarketplaceListing;
use App\Services\CatalogQuery;
use Illuminate\Support\Facades\Gate;

class StockController extends Controller
{
    public function __invoke(IndexRequest $request, CatalogQuery $catalog)
    {
        Gate::authorize('viewAny', MarketplaceListing::class);

        return MarketplaceListingResource::collection($catalog->listingQuery($request, true)->paginate($request->integer('per_page', 20))->withQueryString());
    }
}
