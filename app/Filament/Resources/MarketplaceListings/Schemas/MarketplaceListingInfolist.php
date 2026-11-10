<?php

namespace App\Filament\Resources\MarketplaceListings\Schemas;

use App\Models\MarketplaceListing;
use App\Support\Money;
use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Schema;

class MarketplaceListingInfolist
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                TextEntry::make('product.name')->label('Товар'),
                TextEntry::make('marketplaceAccount.name')->label('Подключение'),
                TextEntry::make('external_id')->label('Внешний ID')->placeholder('Не указан'),
                TextEntry::make('external_sku')->label('SKU на маркетплейсе')->placeholder('Не указан'),
                TextEntry::make('price_minor')->label('Цена')->formatStateUsing(fn (MarketplaceListing $record) => Money::format($record->price_minor, $record->currency)),
                TextEntry::make('stock_quantity')->label('Остаток'),
                TextEntry::make('status')->label('Статус')->formatStateUsing(fn ($state) => $state === 'active' ? 'Активно' : 'Неактивно'),
            ]);
    }
}
