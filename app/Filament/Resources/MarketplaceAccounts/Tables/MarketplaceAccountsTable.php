<?php

namespace App\Filament\Resources\MarketplaceAccounts\Tables;

use App\Models\MarketplaceAccount;
use Filament\Actions\EditAction;
use Filament\Actions\ViewAction;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

class MarketplaceAccountsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('name')->label('Название')->searchable(),
                TextColumn::make('marketplace')->label('Маркетплейс')->formatStateUsing(fn ($state) => MarketplaceAccount::MARKETPLACES[$state]),
                TextColumn::make('status')->label('Статус')->badge()->formatStateUsing(fn ($state) => MarketplaceAccount::STATUSES[$state]),
                IconColumn::make('is_demo')->label('Демо')->boolean(),
                TextColumn::make('listings_count')->label('Размещено товаров')->counts('listings'),
            ])
            ->filters([
                //
            ])
            ->recordActions([
                ViewAction::make(),
                EditAction::make(),
            ])
            ->emptyStateHeading('Маркетплейсы не подключены')->emptyStateDescription('Добавьте подключение или используйте демоорганизацию. Реальные API пока не подключены.');
    }
}
