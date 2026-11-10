<?php

namespace App\Http\Requests\Api\V1;

use App\Models\MarketplaceAccount;
use App\Support\CatalogRules;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Gate;

class MarketplaceAccountRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->route('marketplaceAccount') ? Gate::allows('update', $this->route('marketplaceAccount')) : Gate::allows('create', MarketplaceAccount::class);
    }

    public function rules(): array
    {
        return CatalogRules::forPatch(CatalogRules::account(), $this->isMethod('PATCH'));
    }
}
