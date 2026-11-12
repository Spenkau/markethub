<?php

namespace App\Support;

use Illuminate\Validation\Rule;

class StockOperationRules
{
    public static function rules(?int $organizationId): array
    {
        return [
            'operation_id' => ['required', 'uuid'],
            'type' => ['required', Rule::in(['receipt', 'write_off', 'transfer', 'inventory'])],
            'warehouse_id' => ['required', 'integer', Rule::exists('warehouses', 'id')->where('organization_id', $organizationId)],
            'destination_warehouse_id' => ['required_if:type,transfer', 'prohibited_unless:type,transfer', 'integer', 'different:warehouse_id', Rule::exists('warehouses', 'id')->where('organization_id', $organizationId)],
            'product_id' => ['required', 'integer', Rule::exists('products', 'id')->where('organization_id', $organizationId)],
            'quantity' => ['required', 'integer', 'min:0', 'max:2147483647'],
            'comment' => ['nullable', 'string', 'max:2000'],
            'organization_id' => ['prohibited'], 'user_id' => ['prohibited'], 'occurred_at' => ['prohibited'],
        ];
    }
}
