<?php

namespace App\Models;

use App\Models\Concerns\BelongsToOrganization;
use App\Support\CatalogRules;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\Validator;

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
        Validator::make($this->getAttributes(), CatalogRules::listing((int) $this->organization_id, $this->id, (int) $this->marketplace_account_id))->validate();
    }
}
