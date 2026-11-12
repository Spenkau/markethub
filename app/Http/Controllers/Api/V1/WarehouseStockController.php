<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\InventoryIndexRequest;
use App\Http\Resources\Api\V1\WarehouseStockResource;
use App\Models\Warehouse;
use App\Models\WarehouseStock;
use App\Services\InventoryQuery;
use Illuminate\Support\Facades\Gate;

class WarehouseStockController extends Controller
{
    public function index(InventoryIndexRequest $request, InventoryQuery $queries)
    {
        Gate::authorize('viewAny', WarehouseStock::class);

        return WarehouseStockResource::collection($queries->sorted($queries->stocks($request), $request, ['id', 'quantity', 'updated_at'])->paginate($request->integer('per_page', 20))->withQueryString());
    }

    public function summary(InventoryIndexRequest $request, InventoryQuery $queries)
    {
        Gate::authorize('viewAny', WarehouseStock::class);
        Gate::authorize('viewAny', Warehouse::class);
        $totals = $queries->stocks($request)->reorder()->selectRaw('COUNT(*) AS positions, COUNT(DISTINCT product_id) AS products, COALESCE(SUM(quantity), 0) AS units, COUNT(*) FILTER (WHERE quantity = 0) AS zero_positions, COUNT(*) FILTER (WHERE quantity BETWEEN 1 AND 5) AS low_positions')->first();

        return response()->json(['data' => [
            'warehouses' => Warehouse::query()->count(),
            'active_warehouses' => Warehouse::query()->where('is_active', true)->count(),
            'positions' => (int) $totals->positions, 'products' => (int) $totals->products,
            'units' => (int) $totals->units, 'zero_positions' => (int) $totals->zero_positions, 'low_positions' => (int) $totals->low_positions,
        ]]);
    }
}
