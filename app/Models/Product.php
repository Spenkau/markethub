<?php

namespace App\Models;

use App\Exceptions\DependentRecordsException;
use App\Models\Concerns\BelongsToOrganization;
use App\Support\CatalogRules;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\Validator;

class Product extends Model
{
    use BelongsToOrganization, HasFactory;

    protected $fillable = ['sku', 'name', 'description', 'base_price_minor', 'currency', 'is_active'];

    protected function casts(): array
    {
        return ['base_price_minor' => 'integer', 'is_active' => 'boolean'];
    }

    public function purchaseOrderItems(): HasMany
    {
        return $this->hasMany(PurchaseOrderItem::class);
    }

    public function warehouseStocks(): HasMany
    {
        return $this->hasMany(WarehouseStock::class);
    }

    public function stockMovements(): HasMany
    {
        return $this->hasMany(StockMovement::class);
    }

    public function listings(): HasMany
    {
        return $this->hasMany(MarketplaceListing::class);
    }

    protected function validateBusinessRules(): void
    {
        $this->sku = trim((string) $this->sku);
        $this->name = trim((string) $this->name);
        Validator::make($this->getAttributes(), CatalogRules::product((int) $this->organization_id, $this->id))->validate();
    }

    protected static function booted(): void
    {
        static::deleting(function (Product $product): void {
            if (WarehouseStock::withoutGlobalScopes()->where('product_id', $product->id)->exists() || StockMovement::withoutGlobalScopes()->where('product_id', $product->id)->exists()) {
                throw new DependentRecordsException('Товар используется в складском учёте. Отключите его вместо удаления.');
            }
            if (MarketplaceListing::withoutGlobalScopes()->where('product_id', $product->id)->exists()) {
                throw new DependentRecordsException('Сначала удалите размещения товара.');
            }
        });
    }
}
