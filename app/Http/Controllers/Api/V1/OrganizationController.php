<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\CreateOrganizationRequest;
use App\Http\Requests\Api\V1\IndexRequest;
use App\Http\Requests\Api\V1\SelectOrganizationRequest;
use App\Http\Resources\Api\V1\OrganizationResource;
use App\Models\Organization;
use App\Models\User;
use App\Services\CatalogQuery;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

class OrganizationController extends Controller
{
    public function index(IndexRequest $request, CatalogQuery $catalog)
    {
        return OrganizationResource::collection($catalog->paginate($request->user()->organizations()->getQuery(), $request, ['id' => 'organizations.id', 'name' => 'organizations.name', 'created_at' => 'organizations.created_at']));
    }

    public function store(CreateOrganizationRequest $request)
    {
        $result = DB::transaction(function () use ($request) {
            $slug = 'org-'.strtolower($request->validated('request_id'));
            DB::select('SELECT pg_advisory_xact_lock(hashtextextended(CAST(? AS text), 0))', ['organization:'.$slug]);
            $user = User::whereKey($request->user()->id)->lockForUpdate()->firstOrFail();
            $organization = Organization::where('slug', $slug)->first();
            if ($organization) {
                abort_unless($user->organizations()->whereKey($organization->id)->exists() && $organization->name === $request->validated('name'), 409, 'Ключ запроса уже используется.');

                return [$organization, false];
            }
            $organization = Organization::create(['name' => $request->validated('name'), 'slug' => $slug]);
            $organization->users()->attach($user->id);
            $user->active_organization_id = $organization->id;
            $user->save();

            return [$organization, true];
        });

        return (new OrganizationResource($result[0]))->response()->setStatusCode($result[1] ? 201 : 200);
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
