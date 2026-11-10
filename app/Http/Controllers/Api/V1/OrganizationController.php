<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\IndexRequest;
use App\Http\Requests\Api\V1\SelectOrganizationRequest;
use App\Http\Resources\Api\V1\OrganizationResource;
use App\Services\CatalogQuery;
use Illuminate\Support\Facades\Gate;

class OrganizationController extends Controller
{
    public function index(IndexRequest $request, CatalogQuery $catalog)
    {
        return OrganizationResource::collection($catalog->paginate($request->user()->organizations()->getQuery(), $request, ['id' => 'organizations.id', 'name' => 'organizations.name', 'created_at' => 'organizations.created_at']));
    }

    public function select(SelectOrganizationRequest $request): OrganizationResource
    {
        $organization = $request->user()->organizations()->findOrFail($request->integer('organization_id'));
        Gate::authorize('view', $organization);
        $request->user()->active_organization_id = $organization->id;
        $request->user()->save();

        return new OrganizationResource($organization);
    }
}
