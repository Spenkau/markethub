<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\ProcurementIndexRequest;
use App\Http\Requests\Api\V1\SupplierRequest;
use App\Http\Resources\Api\V1\SupplierResource;
use App\Models\Supplier;
use App\Services\ProcurementQuery;
use Illuminate\Support\Facades\Gate;

class SupplierController extends Controller
{
    public function index(ProcurementIndexRequest $request, ProcurementQuery $queries)
    {
        Gate::authorize('viewAny', Supplier::class);

        return SupplierResource::collection($queries->suppliers($request)->paginate($request->integer('per_page', 20))->withQueryString());
    }

    public function store(SupplierRequest $request)
    {
        return (new SupplierResource(Supplier::create($request->validated())))->response()->setStatusCode(201);
    }

    public function show(Supplier $supplier): SupplierResource
    {
        Gate::authorize('view', $supplier);

        return new SupplierResource($supplier->loadCount('purchaseOrders'));
    }

    public function update(SupplierRequest $request, Supplier $supplier): SupplierResource
    {
        $supplier->update($request->validated());

        return new SupplierResource($supplier->loadCount('purchaseOrders'));
    }

    public function destroy(Supplier $supplier)
    {
        Gate::authorize('delete', $supplier);
        abort_if($supplier->purchaseOrders()->exists(), 409, 'Поставщик используется в закупках. Отключите его вместо удаления.');
        $supplier->delete();

        return response()->noContent();
    }
}
