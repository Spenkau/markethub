<?php

namespace App\Filament\Resources\Products\Tables;

use App\Models\Product;
use App\Support\Money;
use Filament\Actions\EditAction;
use Filament\Actions\ViewAction;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Table;

class ProductsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('sku')->label('SKU')->searchable()->sortable(),
                TextColumn::make('name')->label('Название')->searchable()->sortable()->wrap(),
                TextColumn::make('base_price_minor')->label('Цена')->sortable()->formatStateUsing(fn (Product $record) => Money::format($record->base_price_minor, $record->currency)),
                IconColumn::make('is_active')->label('Активен')->boolean(),
                TextColumn::make('listings_count')->label('Размещения')->counts('listings'),
            ])
            ->filters([
                TernaryFilter::make('is_active')->label('Активность')->trueLabel('Активные')->falseLabel('Неактивные'),
            ])
            ->recordActions([
                ViewAction::make(),
                EditAction::make(),
            ])
            ->emptyStateHeading('Каталог пока пуст')->emptyStateDescription('Создайте товар или привяжите демоорганизацию к администратору.')
            ->defaultSort('id', 'desc');
    }
}
