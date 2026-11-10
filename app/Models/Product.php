<?php

namespace App\Models;

use App\Models\Concerns\BelongsToOrganization;
use App\Support\Money;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class Product extends Model
{
    use BelongsToOrganization, HasFactory;

    protected $fillable = ['sku', 'name', 'description', 'base_price_minor', 'currency', 'is_active'];

    protected function casts(): array
    {
        return ['base_price_minor' => 'integer', 'is_active' => 'boolean'];
    }

    public function listings(): HasMany
    {
        return $this->hasMany(MarketplaceListing::class);
    }

    protected function validateBusinessRules(): void
    {
        $this->sku = trim((string) $this->sku);
        $this->name = trim((string) $this->name);
        Validator::make($this->getAttributes(), [
            'sku' => ['required', 'string', 'max:100', Rule::unique('products')->where('organization_id', $this->organization_id)->ignore($this->id)],
            'name' => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string'],
            'base_price_minor' => ['required', 'integer', 'min:0', 'max:999999999999'],
            'currency' => ['required', Rule::in(array_keys(Money::CURRENCIES))],
            'is_active' => ['required', 'boolean'],
        ])->validate();
    }

    protected static function booted(): void
    {
        static::deleting(function (Product $product): void {
            if (MarketplaceListing::withoutGlobalScopes()->where('product_id', $product->id)->exists()) {
                throw ValidationException::withMessages(['product' => 'Сначала удалите размещения товара.']);
            }
        });
    }
}
