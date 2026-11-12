<?php

namespace App\Http\Requests\Api\V1;

use App\Models\Supplier;
use App\Support\CatalogRules;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Gate;

class SupplierRequest extends FormRequest
{
    protected function prepareForValidation(): void
    {
        if (is_string($this->input('name'))) {
            $this->merge(['name' => trim($this->input('name'))]);
        }
    }

    public function authorize(): bool
    {
        return $this->route('supplier') ? Gate::allows('update', $this->route('supplier')) : Gate::allows('create', Supplier::class);
    }

    public function rules(): array
    {
        return CatalogRules::forPatch(Supplier::rules(), $this->isMethod('PATCH'));
    }
}
