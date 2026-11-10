<?php

namespace App\Http\Resources\Api\V1;

use App\Support\OrganizationContext;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class UserResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return ['id' => $this->id, 'name' => $this->name, 'email' => $this->email, 'is_admin' => $this->is_admin,
            'active_organization_id' => OrganizationContext::id($this->resource)];
    }
}
