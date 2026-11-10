<?php

namespace App\Filament\Resources\MarketplaceListings\Tables;

use App\Filament\Resources\MarketplaceListings\MarketplaceListingResource;
use App\Models\MarketplaceAccount;
use App\Models\MarketplaceListing;
use App\Support\Money;
use Filament\Actions\EditAction;
use Filament\Actions\ViewAction;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class MarketplaceListingsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('product.name')->label('Товар / SKU')->searchable()->wrap()
                    ->description(fn (MarketplaceListing $record) => $record->product->sku),
                TextColumn::make('product.sku')->label('SKU')->searchable()->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('marketplaceAccount.name')->label('Подключение')->searchable()
                    ->description(fn (MarketplaceListing $record) => MarketplaceAccount::MARKETPLACES[$record->marketplaceAccount->marketplace]),
                TextColumn::make('marketplaceAccount.marketplace')->label('Маркетплейс')->formatStateUsing(fn ($state) => MarketplaceAccount::MARKETPLACES[$state])->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('stock_quantity')->label('Доступный остаток')->sortable()->badge()->color(fn ($state) => $state === 0 ? 'danger' : ($state <= 5 ? 'warning' : 'success')),
                TextColumn::make('price_minor')->label('Цена')->sortable()->formatStateUsing(fn (MarketplaceListing $record) => Money::format($record->price_minor, $record->currency)),
                TextColumn::make('status')->label('Статус')->formatStateUsing(fn ($state) => $state === 'active' ? 'Активно' : 'Неактивно')->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                Filter::make('zero_stock')->label('Нулевой остаток')->query(fn (Builder $query) => $query->where('stock_quantity', 0)),
                Filter::make('low_stock')->label('Низкий остаток (1–5)')->query(fn (Builder $query) => $query->whereBetween('stock_quantity', [1, 5])),
                SelectFilter::make('marketplace_account_id')->label('Подключение')->relationship('marketplaceAccount', 'name'),
            ])
            ->recordActions([
                ViewAction::make()->iconButton()->tooltip('Просмотр')->url(fn (MarketplaceListing $record) => MarketplaceListingResource::getUrl('view', ['record' => $record])),
                EditAction::make()->iconButton()->tooltip('Изменить')->url(fn (MarketplaceListing $record) => MarketplaceListingResource::getUrl('edit', ['record' => $record])),
            ])
            ->emptyStateHeading('Нет данных об остатках')->emptyStateDescription('Создайте размещение товара в подключении маркетплейса.')
            ->defaultSort('id', 'desc');
    }
}
