<?php

namespace App\Models;

use App\Models\Concerns\BelongsToOrganization;
use App\Support\Money;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;

class MarketplaceListing extends Model
{
    use BelongsToOrganization, HasFactory;

    protected $fillable = ['product_id', 'marketplace_account_id', 'external_id', 'external_sku', 'price_minor', 'currency', 'stock_quantity', 'status'];

    protected function casts(): array
    {
        return ['price_minor' => 'integer', 'stock_quantity' => 'integer'];
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    public function marketplaceAccount(): BelongsTo
    {
        return $this->belongsTo(MarketplaceAccount::class);
    }

    protected function validateBusinessRules(): void
    {
        $this->external_id = filled($this->external_id) ? trim($this->external_id) : null;
        $sameOrganization = fn ($query) => $query->where('organization_id', $this->organization_id);
        Validator::make($this->getAttributes(), [
            'product_id' => ['required', Rule::exists('products', 'id')->where($sameOrganization),
                Rule::unique('marketplace_listings')->where('marketplace_account_id', $this->marketplace_account_id)->ignore($this->id)],
            'marketplace_account_id' => ['required', Rule::exists('marketplace_accounts', 'id')->where($sameOrganization)],
            'external_id' => ['nullable', 'string', 'max:255', Rule::unique('marketplace_listings')->where('marketplace_account_id', $this->marketplace_account_id)->ignore($this->id)],
            'external_sku' => ['nullable', 'string', 'max:255'],
            'price_minor' => ['required', 'integer', 'min:0', 'max:999999999999'],
            'currency' => ['required', Rule::in(array_keys(Money::CURRENCIES))],
            'stock_quantity' => ['required', 'integer', 'min:0', 'max:2147483647'],
            'status' => ['required', Rule::in(['active', 'inactive'])],
        ])->validate();
    }
}
