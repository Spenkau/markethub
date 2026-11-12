<?php

namespace App\Http\Requests\Api\V1;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class ProcurementIndexRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        $statuses = $this->is('api/v1/goods-receipts') ? ['draft', 'confirmed', 'cancelled'] : ['draft', 'ordered', 'partially_received', 'received', 'cancelled'];

        return [
            'page' => ['sometimes', 'integer', 'min:1'], 'per_page' => ['sometimes', 'integer', 'min:1', 'max:100'],
            'search' => ['sometimes', 'string', 'max:100'], 'is_active' => ['sometimes', 'boolean'],
            'status' => ['sometimes', Rule::in($statuses)],
            'supplier_id' => ['sometimes', 'integer', 'min:1'], 'warehouse_id' => ['sometimes', 'integer', 'min:1'],
            'purchase_order_id' => ['sometimes', 'integer', 'min:1'],
        ];
    }
}
