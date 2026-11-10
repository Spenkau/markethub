<?php

namespace App\Filament\Resources\Shared;

use App\Filament\Resources\MarketplaceListings\Tables\MarketplaceListingsTable;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables\Table;

class ListingsRelationManager extends RelationManager
{
    protected static string $relationship = 'listings';

    protected static ?string $title = 'Размещения';

    protected static bool $isLazy = false;

    public function table(Table $table): Table
    {
        return MarketplaceListingsTable::configure($table);
    }
}
