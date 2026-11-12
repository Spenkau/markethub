<?php

namespace App\Http\Requests\Api\V1;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class InventoryIndexRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'page' => ['sometimes', 'integer', 'min:1'],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:100'],
            'search' => ['sometimes', 'string', 'max:100'],
            'warehouse_id' => ['sometimes', 'integer', 'min:1'],
            'product_id' => ['sometimes', 'integer', 'min:1'],
            'stock' => ['sometimes', Rule::in(['zero', 'low'])],
            'type' => ['sometimes', Rule::in($this->routeIs('warehouses.index') ? ['own', 'marketplace'] : ['receipt', 'write_off', 'transfer', 'inventory'])],
            'is_active' => ['sometimes', 'boolean'],
            'operation_id' => ['sometimes', 'uuid'],
            'date_from' => ['sometimes', 'date_format:Y-m-d'],
            'date_to' => ['sometimes', 'date_format:Y-m-d', ...($this->filled('date_from') ? ['after_or_equal:date_from'] : [])],
            'sort' => ['sometimes', Rule::in(['id', 'name', 'code', 'quantity', 'created_at', 'updated_at', 'occurred_at'])],
            'direction' => ['sometimes', Rule::in(['asc', 'desc'])],
        ];
    }
}
