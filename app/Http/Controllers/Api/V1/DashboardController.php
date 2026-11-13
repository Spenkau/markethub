<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\DashboardRequest;
use App\Models\WarehouseStock;
use App\Services\DashboardQuery;
use Illuminate\Support\Facades\Gate;

class DashboardController extends Controller
{
    public function overview(DashboardRequest $request, DashboardQuery $query)
    {
        Gate::authorize('viewAny', WarehouseStock::class);

        return response()->json(['data' => $query->overview($request)]);
    }
}
