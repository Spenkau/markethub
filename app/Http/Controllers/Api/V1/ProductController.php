<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\IndexRequest;
use App\Http\Requests\Api\V1\ProductRequest;
use App\Http\Resources\Api\V1\ProductResource;
use App\Models\Product;
use App\Services\CatalogQuery;
use Illuminate\Support\Facades\Gate;

class ProductController extends Controller
{
    public function index(IndexRequest $request, CatalogQuery $catalog)
    {
        Gate::authorize('viewAny', Product::class);

        return ProductResource::collection($catalog->productQuery($request)->paginate($request->integer('per_page', 20))->withQueryString());
    }

    public function store(ProductRequest $request)
    {
        return (new ProductResource(Product::create($request->attributesForModel())))->response()->setStatusCode(201);
    }

    public function show(Product $product): ProductResource
    {
        Gate::authorize('view', $product);

        return new ProductResource($product->loadCount('listings')->load(['listings' => fn ($q) => $q->where('origin', 'imported')->with('marketplaceAccount')]));
    }

    public function update(ProductRequest $request, Product $product): ProductResource
    {
        $product->update($request->attributesForModel());

        return new ProductResource($product);
    }

    public function destroy(Product $product)
    {
        Gate::authorize('delete', $product);
        $product->delete();

        return response()->noContent();
    }
}
