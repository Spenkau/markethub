<?php

namespace App\Services;

use App\Http\Requests\Api\V1\InventoryIndexRequest;
use App\Models\StockMovement;
use App\Models\Warehouse;
use App\Models\WarehouseStock;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Validation\ValidationException;

class InventoryQuery
{
    public function warehouses(InventoryIndexRequest $request): Builder
    {
        $query = Warehouse::query()->withCount('stocks');
        if ($request->filled('search')) {
            $query->where(fn ($q) => $q->where('name', 'ilike', '%'.$request->validated('search').'%')->orWhere('code', 'ilike', '%'.$request->validated('search').'%'));
        }
        if ($request->has('is_active')) {
            $query->where('is_active', $request->boolean('is_active'));
        }
        if ($request->has('type')) {
            $query->where('type', $request->validated('type'));
        }

        return $query;
    }

    public function stocks(InventoryIndexRequest $request): Builder
    {
        $query = $this->filter(WarehouseStock::query()->with(['product', 'warehouse']), $request);
        if ($request->validated('stock') === 'zero') {
            $query->where('quantity', 0);
        }
        if ($request->validated('stock') === 'low') {
            $query->whereBetween('quantity', [1, 5]);
        }

        return $query;
    }

    public function movements(InventoryIndexRequest $request): Builder
    {
        $query = $this->filter(StockMovement::query()->with(['product', 'warehouse', 'user', 'receiptItem']), $request);
        foreach (['type', 'operation_id'] as $field) {
            if ($request->has($field)) {
                $query->where($field, $request->validated($field));
            }
        }
        if ($request->has('date_from')) {
            $query->where('occurred_at', '>=', $request->validated('date_from').'T00:00:00+00:00');
        }
        if ($request->has('date_to')) {
            $query->where('occurred_at', '<=', $request->validated('date_to').'T23:59:59.999999+00:00');
        }

        return $query;
    }

    private function filter(Builder $query, InventoryIndexRequest $request): Builder
    {
        foreach (['warehouse_id', 'product_id'] as $field) {
            if ($request->has($field)) {
                $query->where($field, $request->validated($field));
            }
        }
        if ($request->filled('search')) {
            $query->whereHas('product', fn ($q) => $q->where(fn ($q) => $q->where('name', 'ilike', '%'.$request->validated('search').'%')->orWhere('sku', 'ilike', '%'.$request->validated('search').'%')));
        }

        return $query;
    }

    public function sorted(Builder $query, InventoryIndexRequest $request, array $allowed, string $default = 'id', string $direction = 'asc'): Builder
    {
        $sort = $request->validated('sort', $default);
        if (! in_array($sort, $allowed, true)) {
            throw ValidationException::withMessages(['sort' => 'Недопустимое поле сортировки.']);
        }

        return $query->orderBy($sort, $request->validated('direction', $direction))->orderBy('id', $direction);
    }
}
