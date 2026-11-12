<?php

namespace App\Http\Requests\Api\V1;

use App\Models\Warehouse;
use App\Support\CatalogRules;
use App\Support\OrganizationContext;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Gate;

class WarehouseRequest extends FormRequest
{
    protected function prepareForValidation(): void
    {
        if (is_string($this->input('code'))) {
            $this->merge(['code' => strtoupper(trim($this->input('code')))]);
        }
        if (is_string($this->input('name'))) {
            $this->merge(['name' => trim($this->input('name'))]);
        }
    }

    public function authorize(): bool
    {
        return $this->route('warehouse') ? Gate::allows('update', $this->route('warehouse')) : Gate::allows('create', Warehouse::class);
    }

    public function rules(): array
    {
        return CatalogRules::forPatch(Warehouse::rules(OrganizationContext::id(), $this->route('warehouse')?->id), $this->isMethod('PATCH'));
    }
}
