<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\OnboardingPreferencesRequest;
use App\Http\Requests\Api\V1\TourProgressRequest;
use App\Http\Resources\Api\V1\TourProgressResource;
use App\Models\GoodsReceipt;
use App\Models\Organization;
use App\Models\Product;
use App\Models\PurchaseOrder;
use App\Models\Supplier;
use App\Models\User;
use App\Models\UserTourProgress;
use App\Models\Warehouse;
use App\Services\TourProgressService;
use App\Support\OrganizationContext;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

class OnboardingController extends Controller
{
    public function index(Request $request)
    {
        $user = $request->user()->fresh();

        return response()->json(['data' => [
            'preferences' => ['auto_start' => (bool) $user->tour_auto_start, 'checklist_collapsed' => (bool) $user->onboarding_collapsed],
            'progress' => TourProgressResource::collection(UserTourProgress::where('user_id', $request->user()->id)->orderBy('tour_id')->orderBy('version')->get()),
        ]]);
    }

    public function update(TourProgressRequest $request, string $tour, TourProgressService $service)
    {
        return (new TourProgressResource($service->update($request->user(), $tour, $request->validated())))->response()->setStatusCode(200);
    }

    public function preferences(OnboardingPreferencesRequest $request)
    {
        $user = DB::transaction(function () use ($request) {
            $user = User::whereKey($request->user()->id)->lockForUpdate()->firstOrFail();
            if ($request->has('auto_start')) {
                $user->tour_auto_start = $request->boolean('auto_start');
            }
            if ($request->has('checklist_collapsed')) {
                $user->onboarding_collapsed = $request->boolean('checklist_collapsed');
            }
            $user->save();

            return $user;
        });

        return response()->json(['data' => ['auto_start' => (bool) $user->tour_auto_start, 'checklist_collapsed' => (bool) $user->onboarding_collapsed]]);
    }

    public function checklist(Request $request)
    {
        $org = OrganizationContext::id($request->user());
        $steps = [['id' => 'organization', 'completed' => $org !== null, 'can_act' => Gate::allows('create', Organization::class)]];
        foreach (['warehouse' => Warehouse::class, 'product' => Product::class, 'supplier' => Supplier::class, 'purchase' => PurchaseOrder::class, 'receipt' => GoodsReceipt::class] as $id => $model) {
            $allowed = $org !== null && Gate::allows('viewAny', $model);
            $query = $model::query();
            if ($id === 'receipt') {
                $query->where('status', 'confirmed');
            }
            $steps[] = ['id' => $id, 'completed' => $allowed && $query->exists(), 'can_act' => $org !== null && Gate::allows('create', $model)];
        }

        return response()->json(['data' => ['organization_id' => $org, 'steps' => $steps, 'completed' => count(array_filter($steps, fn ($step) => $step['completed'])), 'total' => count($steps)]]);
    }
}
