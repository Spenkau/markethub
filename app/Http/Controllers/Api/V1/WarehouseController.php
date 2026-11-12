<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\InventoryIndexRequest;
use App\Http\Requests\Api\V1\WarehouseRequest;
use App\Http\Resources\Api\V1\WarehouseResource;
use App\Models\Warehouse;
use App\Services\InventoryQuery;
use Illuminate\Support\Facades\Gate;

class WarehouseController extends Controller
{
    public function index(InventoryIndexRequest $request, InventoryQuery $queries)
    {
        Gate::authorize('viewAny', Warehouse::class);

        return WarehouseResource::collection($queries->sorted($queries->warehouses($request), $request, ['id', 'name', 'code', 'created_at'])->paginate($request->integer('per_page', 20))->withQueryString());
    }

    public function store(WarehouseRequest $request)
    {
        return (new WarehouseResource(Warehouse::create($request->validated())))->response()->setStatusCode(201);
    }

    public function show(Warehouse $warehouse): WarehouseResource
    {
        Gate::authorize('view', $warehouse);

        return new WarehouseResource($warehouse->loadCount('stocks'));
    }

    public function update(WarehouseRequest $request, Warehouse $warehouse): WarehouseResource
    {
        $warehouse->update($request->validated());

        return new WarehouseResource($warehouse->loadCount('stocks'));
    }
}
