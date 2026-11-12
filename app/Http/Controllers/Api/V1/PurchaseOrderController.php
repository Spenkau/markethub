<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\ProcurementIndexRequest;
use App\Http\Requests\Api\V1\PurchaseOrderRequest;
use App\Http\Resources\Api\V1\PurchaseOrderResource;
use App\Models\PurchaseOrder;
use App\Services\ProcurementQuery;
use App\Services\ProcurementService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

class PurchaseOrderController extends Controller
{
    public function index(ProcurementIndexRequest $request, ProcurementQuery $queries)
    {
        Gate::authorize('viewAny', PurchaseOrder::class);

        return PurchaseOrderResource::collection($queries->orders($request)->paginate($request->integer('per_page', 20))->withQueryString());
    }

    public function store(PurchaseOrderRequest $request, ProcurementService $service)
    {
        $result = $service->createOrder($request->validated(), $request->user());

        return (new PurchaseOrderResource($result['record']))->additional(['meta' => ['replayed' => $result['replayed']]])->response()->setStatusCode($result['replayed'] ? 200 : 201);
    }

    public function show(PurchaseOrder $purchaseOrder, ProcurementService $service): PurchaseOrderResource
    {
        Gate::authorize('view', $purchaseOrder);

        return new PurchaseOrderResource($service->orderDetails($purchaseOrder));
    }

    public function update(PurchaseOrderRequest $request, PurchaseOrder $purchaseOrder, ProcurementService $service): PurchaseOrderResource
    {
        return new PurchaseOrderResource($service->updateOrder($purchaseOrder, $request->validated(), $request->user()));
    }

    public function order(Request $request, PurchaseOrder $purchaseOrder, ProcurementService $service): PurchaseOrderResource
    {
        return new PurchaseOrderResource($service->transition($purchaseOrder, 'order', $request->user()));
    }

    public function cancel(Request $request, PurchaseOrder $purchaseOrder, ProcurementService $service): PurchaseOrderResource
    {
        return new PurchaseOrderResource($service->transition($purchaseOrder, 'cancel', $request->user()));
    }
}
