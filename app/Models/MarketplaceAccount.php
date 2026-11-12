<?php

namespace App\Models;

use App\Exceptions\DependentRecordsException;
use App\Models\Concerns\BelongsToOrganization;
use App\Support\CatalogRules;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Support\Facades\Validator;

class MarketplaceAccount extends Model
{
    use BelongsToOrganization, HasFactory;

    public const MARKETPLACES = ['wildberries' => 'Wildberries', 'ozon' => 'Ozon', 'shopify' => 'Shopify'];

    public const STATUSES = ['demo' => 'Демо', 'active' => 'Активно', 'disabled' => 'Отключено'];

    protected $dateFormat = 'Y-m-d H:i:sP';

    protected $fillable = ['marketplace', 'name', 'status', 'is_demo'];

    protected $hidden = ['shopify_client_id', 'shopify_client_secret'];

    protected function casts(): array
    {
        return ['configuration' => 'array', 'is_demo' => 'boolean', 'shopify_client_id' => 'encrypted', 'shopify_client_secret' => 'encrypted',
            'sync_products' => 'boolean', 'sync_inventory' => 'boolean', 'shopify_scopes' => 'array',
            'initial_import_at' => 'immutable_datetime', 'last_synced_at' => 'immutable_datetime',
            'last_products_synced_at' => 'immutable_datetime', 'last_full_sync_at' => 'immutable_datetime',
            'next_sync_at' => 'immutable_datetime', 'api_checked_at' => 'immutable_datetime'];
    }

    public function listings(): HasMany
    {
        return $this->hasMany(MarketplaceListing::class);
    }

    public function syncRuns(): HasMany
    {
        return $this->hasMany(MarketplaceSyncRun::class);
    }

    public function credential(): HasOne
    {
        return $this->hasOne(MarketplaceCredential::class);
    }

    public function marketplaceProducts(): HasMany
    {
        return $this->hasMany(MarketplaceProduct::class);
    }

    public function credentialsPayload(): array
    {
        $credential = MarketplaceCredential::withoutGlobalScopes()->where('organization_id', $this->organization_id)->where('marketplace_account_id', $this->id)->first();

        return $credential?->payload ?? ($this->marketplace === 'shopify' ? ['client_id' => $this->shopify_client_id, 'client_secret' => $this->shopify_client_secret] : []);
    }

    protected function validateBusinessRules(): void
    {
        $this->name = trim((string) $this->name);
        Validator::make($this->getAttributes(), CatalogRules::account())->validate();
    }

    protected static function booted(): void
    {
        static::deleting(function (MarketplaceAccount $account): void {
            if (MarketplaceListing::withoutGlobalScopes()->where('marketplace_account_id', $account->id)->exists()) {
                throw new DependentRecordsException('Сначала удалите размещения подключения.');
            }
        });
    }
}
