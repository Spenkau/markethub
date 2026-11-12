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
            'stock' => ['sometimes', Rule::in(['zero', 'low'])],
            'product_id' => ['sometimes', 'integer', 'min:1'],
            'marketplace_account_id' => ['sometimes', 'integer', 'min:1'],
        ];
    }
}
