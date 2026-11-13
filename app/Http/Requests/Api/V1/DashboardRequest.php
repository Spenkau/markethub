<?php

namespace App\Http\Requests\Api\V1;

use App\Services\Marketplaces\ProviderRegistry;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class DashboardRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'provider' => ['sometimes', Rule::in(app(ProviderRegistry::class)->codes())],
            'marketplace_account_id' => ['sometimes', 'integer', 'min:1'],
            'include_demo' => ['sometimes', 'boolean'],
            'origin' => ['sometimes', Rule::in(['local', 'imported', 'demo'])],
            'organization_id' => ['prohibited'],
        ];
    }
}
