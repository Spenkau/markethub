<?php

namespace App\Services;

use App\Http\Requests\Api\V1\IndexRequest;
use App\Models\MarketplaceListing;
use App\Models\Product;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Validation\ValidationException;

class CatalogQuery
{
    public function paginate(Builder $query, IndexRequest $request, array $sorts): LengthAwarePaginator
    {
        return $this->sorted($query, $request, $sorts)->paginate($request->integer('per_page', 20))->withQueryString();
    }

    public function sorted(Builder $query, IndexRequest $request, array $sorts): Builder
    {
        $sort = $request->validated('sort', 'id');
        if (! array_key_exists($sort, $sorts)) {
            throw ValidationException::withMessages(['sort' => 'Недопустимое поле сортировки для этого ресурса.']);
        }

        return $query->orderBy($sorts[$sort], $request->validated('direction', 'asc'))->orderBy('id');
    }

    public function listings(IndexRequest $request): LengthAwarePaginator
    {
        return $this->listingQuery($request)->paginate($request->integer('per_page', 20))->withQueryString();
    }

    public function productQuery(IndexRequest $request): Builder
    {
        $query = Product::query()->withCount('listings');
        if ($request->filled('search')) {
            $query->where(fn ($query) => $query->where('name', 'ilike', '%'.$request->validated('search').'%')->orWhere('sku', 'ilike', '%'.$request->validated('search').'%'));
        }
        if ($request->has('is_active')) {
            $query->where('is_active', $request->boolean('is_active'));
        }

        return $this->sorted($query, $request, ['id' => 'id', 'name' => 'name', 'sku' => 'sku', 'price_minor' => 'base_price_minor', 'created_at' => 'created_at']);
    }

    public function listingQuery(IndexRequest $request): Builder
    {
        $query = MarketplaceListing::query()->with(['product', 'marketplaceAccount']);
        foreach (['product_id', 'marketplace_account_id', 'status'] as $field) {
            if ($request->has($field)) {
                $query->where($field, $request->validated($field));
            }
        }
        if ($request->filled('search')) {
            $query->where(fn ($query) => $query->whereHas('product', fn ($query) => $query->where('name', 'ilike', '%'.$request->validated('search').'%')->orWhere('sku', 'ilike', '%'.$request->validated('search').'%'))
                ->orWhere('external_id', 'ilike', '%'.$request->validated('search').'%'));
        }
        if ($request->has('marketplace')) {
            $query->whereHas('marketplaceAccount', fn ($query) => $query->where('marketplace', $request->validated('marketplace')));
        }
        if ($request->validated('stock') === 'zero') {
            $query->where('stock_quantity', 0);
        } elseif ($request->validated('stock') === 'low') {
            $query->whereBetween('stock_quantity', [1, 5]);
        }

        return $this->sorted($query, $request, ['id' => 'id', 'price_minor' => 'price_minor', 'stock_quantity' => 'stock_quantity', 'created_at' => 'created_at']);
    }
}
