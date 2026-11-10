<?php

namespace App\Http\Requests\Api\V1;

use App\Models\Product;
use App\Support\CatalogRules;
use App\Support\OrganizationContext;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Gate;

class ProductRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->route('product') ? Gate::allows('update', $this->route('product')) : Gate::allows('create', Product::class);
    }

    public function rules(): array
    {
        $rules = CatalogRules::product(OrganizationContext::id(), $this->route('product')?->id);
        $rules['price_minor'] = $rules['base_price_minor'];
        unset($rules['base_price_minor']);

        return CatalogRules::forPatch($rules, $this->isMethod('PATCH')) + ['base_price_minor' => ['prohibited']];
    }

    public function attributesForModel(): array
    {
        $data = $this->validated();
        if (array_key_exists('price_minor', $data)) {
            $data['base_price_minor'] = $data['price_minor'];
            unset($data['price_minor']);
        }

        return $data;
    }
}
