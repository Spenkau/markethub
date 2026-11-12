<?php

namespace App\Http\Requests\Api\V1;

use App\Models\MarketplaceAccount;
use App\Support\OrganizationContext;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;

class ShopifyConnectionRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->route('marketplaceAccount') ? Gate::allows('update', $this->route('marketplaceAccount')) : Gate::allows('create', MarketplaceAccount::class);
    }

    protected function prepareForValidation(): void
    {
        if ($this->has('shop_domain')) {
            $this->merge(['shop_domain' => strtolower(trim((string) $this->input('shop_domain')))]);
        }
    }

    public function rules(): array
    {
        $creating = $this->isMethod('POST');

        return [
            'name' => [$creating ? 'required' : 'sometimes', 'string', 'max:255'],
            'shop_domain' => [$creating ? 'required' : 'prohibited', 'string', 'max:255', 'regex:/^[a-z0-9][a-z0-9-]*\.myshopify\.com$/', Rule::unique('marketplace_accounts')->where('organization_id', OrganizationContext::id())],
            'client_id' => [$creating ? 'required' : 'sometimes', 'string', 'max:255'],
            'client_secret' => [$creating ? 'required' : 'sometimes', 'string', 'max:255'],
            'sync_frequency' => ['sometimes', Rule::in(['manual', 'hourly', 'daily'])],
            'sync_products' => ['sometimes', 'boolean'], 'sync_inventory' => ['sometimes', 'boolean'],
            'status' => ['sometimes', Rule::in(['active', 'disabled'])],
            'organization_id' => ['prohibited'], 'is_demo' => ['prohibited'], 'marketplace' => ['prohibited'],
        ];
    }
}
