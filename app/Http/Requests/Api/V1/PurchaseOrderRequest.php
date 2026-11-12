<?php

namespace App\Http\Requests\Api\V1;

use App\Models\PurchaseOrder;
use App\Support\CatalogRules;
use App\Support\Money;
use App\Support\OrganizationContext;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;

class PurchaseOrderRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->route('purchaseOrder') ? Gate::allows('update', $this->route('purchaseOrder')) : Gate::allows('create', PurchaseOrder::class);
    }

    public function rules(): array
    {
        $org = OrganizationContext::id();

        // PATCH replaces the full draft, including its lines, to avoid ambiguous partial edits.
        return CatalogRules::forPatch([
            'request_id' => $this->isMethod('POST') ? ['required', 'uuid'] : ['prohibited'],
            'supplier_id' => ['required', 'integer', Rule::exists('suppliers', 'id')->where('organization_id', $org)],
            'warehouse_id' => ['required', 'integer', Rule::exists('warehouses', 'id')->where('organization_id', $org)],
            'currency' => ['required', Rule::in(array_keys(Money::CURRENCIES))],
            'comment' => ['nullable', 'string', 'max:5000'],
            'items' => ['required', 'array', 'min:1', 'max:100'],
            'items.*.product_id' => ['required', 'integer', 'distinct', Rule::exists('products', 'id')->where('organization_id', $org)],
            'items.*.quantity' => ['required', 'integer', 'min:1', 'max:2147483647'],
            'items.*.received_quantity' => ['prohibited'], 'items.*.line_total' => ['prohibited'], 'items.*.organization_id' => ['prohibited'],
            'items.*.unit_price' => ['required', 'string', 'regex:/^\d{1,10}(\.\d{1,2})?$/'],
            'status' => ['prohibited'], 'total_amount' => ['prohibited'], 'created_by' => ['prohibited'],
        ], false);
    }
}
