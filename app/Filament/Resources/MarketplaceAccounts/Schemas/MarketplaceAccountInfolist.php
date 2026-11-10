<?php

namespace App\Filament\Resources\MarketplaceAccounts\Schemas;

use App\Models\MarketplaceAccount;
use Filament\Infolists\Components\IconEntry;
use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Schema;

class MarketplaceAccountInfolist
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                TextEntry::make('name')->label('Название'),
                TextEntry::make('marketplace')->label('Маркетплейс')->formatStateUsing(fn ($state) => MarketplaceAccount::MARKETPLACES[$state]),
                TextEntry::make('status')->label('Статус')->badge()->formatStateUsing(fn ($state) => MarketplaceAccount::STATUSES[$state]),
                IconEntry::make('is_demo')->label('Демо')->boolean(),
                TextEntry::make('listings_count')->label('Размещено товаров')->state(fn (MarketplaceAccount $record) => $record->listings()->count()),
            ]);
    }
}
