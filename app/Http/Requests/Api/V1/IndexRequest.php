<?php

namespace App\Http\Requests\Api\V1;

use App\Services\Marketplaces\ProviderRegistry;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class IndexRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        if ($this->filled('provider') && ! $this->filled('marketplace')) {
            $this->merge(['marketplace' => $this->input('provider')]);
        }
    }

    public function rules(): array
    {
        return [
            'search' => ['sometimes', 'string', 'max:100'],
            'page' => ['sometimes', 'integer', 'min:1'], 'per_page' => ['sometimes', 'integer', 'min:1', 'max:100'],
            'sort' => ['sometimes', Rule::in(['id', 'name', 'sku', 'price_minor', 'stock_quantity', 'created_at'])],
            'direction' => ['sometimes', Rule::in(['asc', 'desc'])],
            'is_active' => ['sometimes', 'boolean'], 'is_demo' => ['sometimes', 'boolean'],
            'marketplace' => ['sometimes', Rule::in(app(ProviderRegistry::class)->codes())],
            'status' => ['sometimes', Rule::in(['demo', 'active', 'disabled', 'inactive'])],
            'mapping' => ['sometimes', Rule::in(['mapped', 'unmapped'])], 'origin' => ['sometimes', Rule::in(['local', 'demo', 'imported'])],
            'stock' => ['sometimes', Rule::in(['zero', 'low', 'positive', 'negative', 'unknown'])],
            'location_id' => ['sometimes', 'integer', 'min:1'],
            'is_current' => ['sometimes', 'boolean'], 'tracked' => ['sometimes', 'boolean'], 'location_active' => ['sometimes', 'boolean'],
            'provider' => ['sometimes', Rule::in(app(ProviderRegistry::class)->codes())],
            'product_id' => ['sometimes', 'integer', 'min:1'],
            'marketplace_account_id' => ['sometimes', 'integer', 'min:1'],
        ];
    }
}
