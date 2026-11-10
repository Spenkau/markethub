<?php

namespace App\Filament\Resources\Products\Schemas;

use App\Filament\Support\CatalogFields;
use App\Support\OrganizationContext;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Schema;
use Illuminate\Validation\Rules\Unique;

class ProductForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                TextInput::make('sku')->label('SKU')->required()->maxLength(100)
                    ->unique(ignoreRecord: true, modifyRuleUsing: fn (Unique $rule) => $rule->where('organization_id', OrganizationContext::id())),
                TextInput::make('name')->label('Название')->required()->maxLength(255),
                Textarea::make('description')->label('Описание')->columnSpanFull(),
                CatalogFields::price('base_price_minor'),
                CatalogFields::currency(),
                Toggle::make('is_active')->label('Активен')->default(true)->required(),
            ]);
    }
}
