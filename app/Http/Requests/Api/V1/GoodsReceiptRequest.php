<?php

namespace App\Http\Requests\Api\V1;

use App\Models\GoodsReceipt;
use App\Support\CatalogRules;
use App\Support\OrganizationContext;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;

class GoodsReceiptRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->route('goodsReceipt') ? Gate::allows('update', $this->route('goodsReceipt')) : Gate::allows('create', GoodsReceipt::class);
    }

    public function rules(): array
    {
        $org = OrganizationContext::id();

        return CatalogRules::forPatch([
            'request_id' => $this->isMethod('POST') ? ['required', 'uuid'] : ['prohibited'],
            'purchase_order_id' => $this->isMethod('POST') ? ['required', 'integer', Rule::exists('purchase_orders', 'id')->where('organization_id', $org)] : ['prohibited'],
            'items' => ['required', 'array', 'min:1', 'max:100'],
            'items.*.purchase_order_item_id' => ['required', 'integer', 'distinct', Rule::exists('purchase_order_items', 'id')->where('organization_id', $org)],
            'warehouse_id' => ['prohibited'], 'supplier_id' => ['prohibited'],
            'items.*.product_id' => ['prohibited'], 'items.*.stock_movement_id' => ['prohibited'], 'items.*.operation_id' => ['prohibited'],
            'items.*.quantity' => ['required', 'integer', 'min:1', 'max:2147483647'],
            'comment' => ['nullable', 'string', 'max:5000'], 'status' => ['prohibited'], 'confirmed_by' => ['prohibited'], 'confirmed_at' => ['prohibited'], 'created_by' => ['prohibited'],
        ], false);
    }
}
