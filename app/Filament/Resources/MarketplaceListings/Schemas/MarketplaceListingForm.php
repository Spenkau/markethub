<?php

namespace App\Filament\Resources\MarketplaceListings\Schemas;

use App\Filament\Support\CatalogFields;
use App\Models\MarketplaceListing;
use App\Support\OrganizationContext;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;
use Illuminate\Validation\Rule;

class MarketplaceListingForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                Select::make('product_id')->label('Товар')->relationship('product', 'name')->searchable()->preload()->required()->live()
                    ->rules(fn (Get $get, ?MarketplaceListing $record) => [Rule::exists('products', 'id')->where('organization_id', OrganizationContext::id()),
                        Rule::unique('marketplace_listings', 'product_id')->where('marketplace_account_id', $get('marketplace_account_id'))->ignore($record?->id)]),
                Select::make('marketplace_account_id')->label('Подключение')->relationship('marketplaceAccount', 'name')->searchable()->preload()->required()->live()
                    ->rules([Rule::exists('marketplace_accounts', 'id')->where('organization_id', OrganizationContext::id())]),
                TextInput::make('external_id')->label('Внешний ID')->maxLength(255)->helperText('Для демо используйте SYNTHETIC-. Не вводите API-ключи.')
                    ->rules(fn (Get $get, ?MarketplaceListing $record) => [Rule::unique('marketplace_listings', 'external_id')->where('marketplace_account_id', $get('marketplace_account_id'))->ignore($record?->id)]),
                TextInput::make('external_sku')->label('SKU на маркетплейсе')->maxLength(255),
                CatalogFields::price('price_minor'), CatalogFields::currency(),
                TextInput::make('stock_quantity')->label('Доступный остаток')->numeric()->integer()->minValue(0)->maxValue(2147483647)->required()->default(0),
                Select::make('status')->label('Статус')->options(['active' => 'Активно', 'inactive' => 'Неактивно'])->default('active')->required(),
            ]);
    }
}
