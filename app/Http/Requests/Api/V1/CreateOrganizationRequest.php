<?php

namespace App\Http\Requests\Api\V1;

use App\Models\Organization;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Gate;

class CreateOrganizationRequest extends FormRequest
{
    protected function prepareForValidation(): void
    {
        if (is_string($this->input('name'))) {
            $this->merge(['name' => trim($this->input('name'))]);
        }
    }

    public function authorize(): bool
    {
        return Gate::allows('create', Organization::class);
    }

    public function rules(): array
    {
        return ['name' => ['required', 'string', 'max:255'], 'request_id' => ['required', 'uuid'], 'user_id' => ['prohibited'], 'organization_id' => ['prohibited'], 'slug' => ['prohibited'], 'is_admin' => ['prohibited']];
    }
}
