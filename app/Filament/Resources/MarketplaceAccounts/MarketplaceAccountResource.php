<?php

namespace App\Filament\Resources\MarketplaceAccounts;

use App\Filament\Resources\MarketplaceAccounts\Pages\CreateMarketplaceAccount;
use App\Filament\Resources\MarketplaceAccounts\Pages\EditMarketplaceAccount;
use App\Filament\Resources\MarketplaceAccounts\Pages\ListMarketplaceAccounts;
use App\Filament\Resources\MarketplaceAccounts\Pages\ViewMarketplaceAccount;
use App\Filament\Resources\MarketplaceAccounts\Schemas\MarketplaceAccountForm;
use App\Filament\Resources\MarketplaceAccounts\Schemas\MarketplaceAccountInfolist;
use App\Filament\Resources\MarketplaceAccounts\Tables\MarketplaceAccountsTable;
use App\Filament\Resources\Shared\ListingsRelationManager;
use App\Models\MarketplaceAccount;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;

class MarketplaceAccountResource extends Resource
{
    protected static ?string $model = MarketplaceAccount::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedBuildingStorefront;

    protected static ?string $slug = 'marketplaces';

    protected static ?string $modelLabel = 'подключение';

    protected static ?string $pluralModelLabel = 'Маркетплейсы';

    protected static ?int $navigationSort = 40;

    protected static ?string $recordTitleAttribute = 'name';

    public static function form(Schema $schema): Schema
    {
        return MarketplaceAccountForm::configure($schema);
    }

    public static function infolist(Schema $schema): Schema
    {
        return MarketplaceAccountInfolist::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return MarketplaceAccountsTable::configure($table);
    }

    public static function getRelations(): array
    {
        return [
            ListingsRelationManager::class,
        ];
    }

    public static function getPages(): array
    {
        return [
            'index' => ListMarketplaceAccounts::route('/'),
            'create' => CreateMarketplaceAccount::route('/create'),
            'view' => ViewMarketplaceAccount::route('/{record}'),
            'edit' => EditMarketplaceAccount::route('/{record}/edit'),
        ];
    }
}
