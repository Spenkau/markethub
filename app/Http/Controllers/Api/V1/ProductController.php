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
        $query = Product::query()->withCount('listings');
        if ($request->filled('search')) {
            $query->where(fn ($query) => $query->where('name', 'ilike', '%'.$request->validated('search').'%')->orWhere('sku', 'ilike', '%'.$request->validated('search').'%'));
        }
        if ($request->has('is_active')) {
            $query->where('is_active', $request->boolean('is_active'));
        }

        return ProductResource::collection($catalog->paginate($query, $request, ['id' => 'id', 'name' => 'name', 'sku' => 'sku', 'price_minor' => 'base_price_minor', 'created_at' => 'created_at']));
    }

    public function store(ProductRequest $request)
    {
        return (new ProductResource(Product::create($request->attributesForModel())))->response()->setStatusCode(201);
    }

    public function show(Product $product): ProductResource
    {
        Gate::authorize('view', $product);

        return new ProductResource($product->loadCount('listings'));
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
