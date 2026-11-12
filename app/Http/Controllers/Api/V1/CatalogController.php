<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\CatalogIndexRequest;
use App\Models\Product;
use App\Services\UnifiedCatalogPresenter;
use App\Services\UnifiedCatalogQuery;
use Illuminate\Support\Facades\Gate;

class CatalogController extends Controller
{
    public function index(CatalogIndexRequest $request, UnifiedCatalogQuery $query, UnifiedCatalogPresenter $presenter)
    {
        Gate::authorize('viewAny', Product::class);
        $page = $query->query($request)->paginate($request->integer('per_page', 20))->withQueryString();
        $page->setCollection($presenter->page($page->getCollection()));

        return response()->json(['data' => $page->items(), 'meta' => ['current_page' => $page->currentPage(), 'last_page' => $page->lastPage(), 'per_page' => $page->perPage(), 'total' => $page->total()], 'links' => ['first' => $page->url(1), 'last' => $page->url($page->lastPage()), 'prev' => $page->previousPageUrl(), 'next' => $page->nextPageUrl()]]);
    }

    public function show(CatalogIndexRequest $request, string $kind, int $id, UnifiedCatalogPresenter $presenter)
    {
        Gate::authorize('viewAny', Product::class);
        abort_unless(in_array($kind, ['local', 'external_product', 'external_variant'], true), 404);

        return response()->json(['data' => $presenter->detail($kind, $id, $request->integer('page', 1))]);
    }
}
