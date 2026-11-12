<?php

namespace App\Http\Controllers\Api\V1;

use App\Exports\CatalogExport;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\IndexRequest;
use App\Models\MarketplaceListing;
use App\Models\Product;
use App\Services\CatalogQuery;
use App\Support\OrganizationContext;
use Illuminate\Support\Facades\Gate;
use Maatwebsite\Excel\Excel;

class ExportController extends Controller
{
    public function products(IndexRequest $request, CatalogQuery $catalog, Excel $excel)
    {
        Gate::authorize('viewAny', Product::class);
        abort_if(OrganizationContext::id() === null, 403);

        return $excel->download(new CatalogExport($catalog->productQuery($request)), 'products.xlsx', Excel::XLSX, ['Cache-Control' => 'private, no-store']);
    }

    public function stocks(IndexRequest $request, CatalogQuery $catalog, Excel $excel)
    {
        Gate::authorize('viewAny', MarketplaceListing::class);
        abort_if(OrganizationContext::id() === null, 403);

        return $excel->download(new CatalogExport($catalog->listingQuery($request), true), 'stocks.xlsx', Excel::XLSX, ['Cache-Control' => 'private, no-store']);
    }
}
