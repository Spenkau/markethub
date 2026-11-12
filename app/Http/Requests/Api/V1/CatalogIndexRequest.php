<?php

namespace App\Http\Requests\Api\V1;

use Illuminate\Validation\Rule;

class CatalogIndexRequest extends IndexRequest
{
    public function rules(): array
    {
        return [...parent::rules(), 'kind' => ['sometimes', Rule::in(['local', 'external'])], 'sort' => ['sometimes', Rule::in(['id', 'name', 'sku', 'price_minor', 'created_at'])]];
    }
}
