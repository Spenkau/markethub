<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\GoodsReceiptRequest;
use App\Http\Requests\Api\V1\ProcurementIndexRequest;
use App\Http\Resources\Api\V1\GoodsReceiptResource;
use App\Models\GoodsReceipt;
use App\Services\ProcurementQuery;
use App\Services\ProcurementService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

class GoodsReceiptController extends Controller
{
    public function index(ProcurementIndexRequest $request, ProcurementQuery $queries)
    {
        Gate::authorize('viewAny', GoodsReceipt::class);

        return GoodsReceiptResource::collection($queries->receipts($request)->paginate($request->integer('per_page', 20))->withQueryString());
    }

    public function store(GoodsReceiptRequest $request, ProcurementService $service)
    {
        $result = $service->createReceipt($request->validated(), $request->user());

        return (new GoodsReceiptResource($result['record']))->additional(['meta' => ['replayed' => $result['replayed']]])->response()->setStatusCode($result['replayed'] ? 200 : 201);
    }

    public function show(GoodsReceipt $goodsReceipt, ProcurementService $service): GoodsReceiptResource
    {
        Gate::authorize('view', $goodsReceipt);

        return new GoodsReceiptResource($service->receiptDetails($goodsReceipt));
    }

    public function update(GoodsReceiptRequest $request, GoodsReceipt $goodsReceipt, ProcurementService $service): GoodsReceiptResource
    {
        return new GoodsReceiptResource($service->updateReceipt($goodsReceipt, $request->validated(), $request->user()));
    }

    public function confirm(Request $request, GoodsReceipt $goodsReceipt, ProcurementService $service)
    {
        $result = $service->confirm($goodsReceipt, $request->user());

        return (new GoodsReceiptResource($result['record']))->additional(['meta' => ['replayed' => $result['replayed']]]);
    }

    public function cancel(Request $request, GoodsReceipt $goodsReceipt, ProcurementService $service): GoodsReceiptResource
    {
        return new GoodsReceiptResource($service->cancelReceipt($goodsReceipt, $request->user()));
    }
}
