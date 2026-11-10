<?php

namespace App\Models;

use App\Exceptions\DependentRecordsException;
use App\Models\Concerns\BelongsToOrganization;
use App\Support\CatalogRules;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\Validator;

class MarketplaceAccount extends Model
{
    use BelongsToOrganization, HasFactory;

    public const MARKETPLACES = ['wildberries' => 'Wildberries', 'ozon' => 'Ozon'];

    public const STATUSES = ['demo' => 'Демо', 'active' => 'Активно', 'disabled' => 'Отключено'];

    protected $fillable = ['marketplace', 'name', 'status', 'is_demo'];

    protected function casts(): array
    {
        return ['is_demo' => 'boolean'];
    }

    public function listings(): HasMany
    {
        return $this->hasMany(MarketplaceListing::class);
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
