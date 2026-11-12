<?php

namespace App\Http\Requests\Api\V1;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class ShopifyIndexRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'page' => ['sometimes', 'integer', 'min:1'], 'per_page' => ['sometimes', 'integer', 'min:1', 'max:100'],
            'search' => ['sometimes', 'string', 'max:100'],
            'status' => ['sometimes', Rule::in(['active', 'draft', 'archived', 'deleted', 'queued', 'running', 'completed', 'partial', 'failed'])],
            'mapping' => ['sometimes', Rule::in(['mapped', 'unmapped'])],
            'location_id' => ['sometimes', 'integer', 'min:1'], 'product_id' => ['sometimes', 'integer', 'min:1'],
            'trigger' => ['sometimes', Rule::in(['manual', 'automatic'])],
            'date_from' => ['sometimes', 'date_format:Y-m-d'], 'date_to' => ['sometimes', 'date_format:Y-m-d', 'after_or_equal:date_from'],
        ];
    }
}
