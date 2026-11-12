<?php

namespace App\Models;

use App\Models\Concerns\BelongsToOrganization;
use App\Support\CatalogRules;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\Validator;

class MarketplaceListing extends Model
{
    use BelongsToOrganization, HasFactory;

    protected $fillable = ['product_id', 'marketplace_account_id', 'external_id', 'external_sku', 'price_minor', 'currency', 'stock_quantity', 'status'];

    public function inventoryLevels(): HasMany
    {
        return $this->hasMany(MarketplaceInventoryLevel::class);
    }

    protected function casts(): array
    {
        return ['price_minor' => 'integer', 'stock_quantity' => 'integer', 'source_data' => 'array', 'source_synced_at' => 'immutable_datetime', 'source_created_at' => 'immutable_datetime', 'source_updated_at' => 'immutable_datetime'];
    }

    public function marketplaceProduct(): BelongsTo
    {
        return $this->belongsTo(MarketplaceProduct::class);
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    public function marketplaceAccount(): BelongsTo
    {
        return $this->belongsTo(MarketplaceAccount::class);
    }

    protected static function booted(): void
    {
        static::creating(function (MarketplaceListing $listing) {
            if (! $listing->origin) {
                $demo = MarketplaceAccount::withoutGlobalScopes()->where('organization_id', $listing->organization_id)->whereKey($listing->marketplace_account_id)->value('is_demo');
                $listing->origin = $demo ? 'demo' : 'local';
            }
        });
    }

    protected function validateBusinessRules(): void
    {
        $this->external_id = filled($this->external_id) ? trim($this->external_id) : null;
        Validator::make($this->getAttributes(), CatalogRules::listing((int) $this->organization_id, $this->id, (int) $this->marketplace_account_id))->validate();
    }
}
