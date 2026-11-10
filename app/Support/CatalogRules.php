<?php

namespace App\Support;

use App\Models\MarketplaceAccount;
use Illuminate\Validation\Rule;

class CatalogRules
{
    public static function product(?int $organizationId, ?int $id = null): array
    {
        return [
            'sku' => ['required', 'string', 'max:100', Rule::unique('products')->where('organization_id', $organizationId)->ignore($id)],
            'name' => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string', 'max:10000'],
            'base_price_minor' => ['required', 'integer', 'min:0', 'max:999999999999'],
            'currency' => ['required', Rule::in(array_keys(Money::CURRENCIES))],
            'is_active' => ['required', 'boolean'],
        ];
    }

    public static function account(): array
    {
        return [
            'name' => ['required', 'string', 'max:255'],
            'marketplace' => ['required', Rule::in(array_keys(MarketplaceAccount::MARKETPLACES))],
            'status' => ['required', Rule::in(array_keys(MarketplaceAccount::STATUSES))],
            'is_demo' => ['required', 'boolean'],
        ];
    }

    public static function listing(?int $organizationId, ?int $id = null, ?int $accountId = null): array
    {
        return [
            'product_id' => ['required', 'integer', Rule::exists('products', 'id')->where('organization_id', $organizationId),
                Rule::unique('marketplace_listings')->where('marketplace_account_id', $accountId)->ignore($id)],
            'marketplace_account_id' => ['required', 'integer', Rule::exists('marketplace_accounts', 'id')->where('organization_id', $organizationId)],
            'external_id' => ['nullable', 'string', 'max:255', Rule::unique('marketplace_listings')->where('marketplace_account_id', $accountId)->ignore($id)],
            'external_sku' => ['nullable', 'string', 'max:255'],
            'price_minor' => ['required', 'integer', 'min:0', 'max:999999999999'],
            'currency' => ['required', Rule::in(array_keys(Money::CURRENCIES))],
            'stock_quantity' => ['required', 'integer', 'min:0', 'max:2147483647'],
            'status' => ['required', Rule::in(['active', 'inactive'])],
        ];
    }

    public static function forPatch(array $rules, bool $patch): array
    {
        if ($patch) {
            foreach ($rules as &$field) {
                array_unshift($field, 'sometimes');
            }
        }

        return $rules + ['organization_id' => ['prohibited'], 'id' => ['prohibited'], 'created_at' => ['prohibited'], 'updated_at' => ['prohibited'], 'is_admin' => ['prohibited'], 'active_organization_id' => ['prohibited']];
    }
}
