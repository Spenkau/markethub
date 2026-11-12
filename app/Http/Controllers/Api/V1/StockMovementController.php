<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\InventoryIndexRequest;
use App\Http\Resources\Api\V1\StockMovementResource;
use App\Models\StockMovement;
use App\Services\InventoryQuery;
use Illuminate\Support\Facades\Gate;

class StockMovementController extends Controller
{
    public function index(InventoryIndexRequest $request, InventoryQuery $queries)
    {
        Gate::authorize('viewAny', StockMovement::class);

        return StockMovementResource::collection($queries->sorted($queries->movements($request), $request, ['id', 'occurred_at'], 'occurred_at', 'desc')->paginate($request->integer('per_page', 20))->withQueryString());
    }
}
