<?php

namespace App\Filament\Resources\Products\Schemas;

use App\Models\Product;
use App\Support\Money;
use Filament\Infolists\Components\IconEntry;
use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Schema;

class ProductInfolist
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                TextEntry::make('sku')->label('SKU'),
                TextEntry::make('name')->label('Название'),
                TextEntry::make('description')->label('Описание')->placeholder('Не указано')->columnSpanFull(),
                TextEntry::make('base_price_minor')->label('Цена')->formatStateUsing(fn (Product $record) => Money::format($record->base_price_minor, $record->currency)),
                IconEntry::make('is_active')->label('Активен')->boolean(),
            ]);
    }
}
