<?php

namespace App\Services;

use App\Http\Requests\Api\V1\IndexRequest;
use App\Models\MarketplaceListing;
use App\Models\Product;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
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

        return $query->orderBy($sorts[$sort], $request->validated('direction', 'asc'))->orderBy($query->getModel()->qualifyColumn('id'));
    }

    public function listings(IndexRequest $request): LengthAwarePaginator
    {
        return $this->listingQuery($request)->paginate($request->integer('per_page', 20))->withQueryString();
    }

    public function productQuery(IndexRequest $request): Builder
    {
        $query = Product::query()->withCount('listings')->with(['listings' => fn ($q) => $q->where('marketplace_listings.origin', 'imported')->with(['marketplaceAccount', 'marketplaceProduct'])]);
        if ($request->filled('marketplace') || $request->filled('marketplace_account_id')) {
            $query->whereHas('listings', function ($q) use ($request) {
                if ($request->filled('marketplace_account_id')) {
                    $q->where('marketplace_account_id', $request->integer('marketplace_account_id'));
                }
                if ($request->filled('marketplace')) {
                    $q->whereHas('marketplaceAccount', fn ($a) => $a->where('marketplace', $request->validated('marketplace')));
                }
            });
        }
        if ($request->filled('search')) {
            $query->where(fn ($query) => $query->where('name', 'ilike', '%'.$request->validated('search').'%')->orWhere('sku', 'ilike', '%'.$request->validated('search').'%'));
        }
        if ($request->has('is_active')) {
            $query->where('is_active', $request->boolean('is_active'));
        }

        return $this->sorted($query, $request, ['id' => 'id', 'name' => 'name', 'sku' => 'sku', 'price_minor' => 'base_price_minor', 'created_at' => 'created_at']);
    }

    public function listingQuery(IndexRequest $request, bool $includeSources = false): Builder
    {
        $query = MarketplaceListing::query()->with(['product', 'marketplaceAccount', 'marketplaceProduct']);
        foreach (['product_id', 'marketplace_account_id', 'status'] as $field) {
            if ($request->has($field)) {
                $query->where('marketplace_listings.'.$field, $request->validated($field));
            }
        }
        if ($request->filled('search')) {
            $query->where(fn ($query) => $query->whereHas('product', fn ($query) => $query->where('name', 'ilike', '%'.$request->validated('search').'%')->orWhere('sku', 'ilike', '%'.$request->validated('search').'%'))
                ->orWhereHas('marketplaceProduct', fn ($p) => $p->where('title', 'ilike', '%'.$request->validated('search').'%'))->orWhere('marketplace_listings.external_sku', 'ilike', '%'.$request->validated('search').'%')->orWhere('marketplace_listings.barcode', 'ilike', '%'.$request->validated('search').'%')->orWhere('marketplace_listings.external_id', 'ilike', '%'.$request->validated('search').'%'));
        }
        if ($request->has('marketplace')) {
            $query->whereHas('marketplaceAccount', fn ($query) => $query->where('marketplace', $request->validated('marketplace')));
        }
        if ($request->filled('mapping')) {
            $request->validated('mapping') === 'unmapped' ? $query->whereNull('marketplace_listings.product_id') : $query->whereNotNull('marketplace_listings.product_id');
        }
        if ($request->filled('origin')) {
            $query->where('marketplace_listings.origin', $request->validated('origin'));
        }
        if ($includeSources) {
            app(MarketplaceInventoryQuery::class)->decorate($query, $request);
        } elseif ($request->validated('stock') === 'zero') {
            $query->where('stock_quantity', 0);
        } elseif ($request->validated('stock') === 'low') {
            $query->whereBetween('stock_quantity', [1, 5]);
        }

        $sorted = $this->sorted($query, $request, ['id' => 'marketplace_listings.id', 'price_minor' => 'marketplace_listings.price_minor', 'stock_quantity' => $includeSources ? DB::raw("CASE WHEN marketplace_listings.origin = 'imported' THEN inventory.available ELSE marketplace_listings.stock_quantity END") : 'marketplace_listings.stock_quantity', 'created_at' => 'marketplace_listings.created_at']);

        return $includeSources ? $sorted->orderBy('inventory.id') : $sorted;
    }
}
