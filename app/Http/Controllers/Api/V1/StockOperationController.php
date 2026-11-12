<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\StockOperationRequest;
use App\Http\Resources\Api\V1\StockMovementResource;
use App\Services\StockOperationService;

class StockOperationController extends Controller
{
    public function store(StockOperationRequest $request, StockOperationService $service)
    {
        $result = $service->execute($request->validated(), $request->user());

        return response()->json([
            'data' => ['operation_id' => $result['movements']->first()->operation_id, 'movements' => StockMovementResource::collection($result['movements'])],
            'meta' => ['replayed' => $result['replayed']],
        ], $result['replayed'] ? 200 : 201);
    }
}
