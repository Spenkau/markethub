<?php

namespace App\Filament\Support;

use App\Support\Money;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;

class CatalogFields
{
    public static function price(string $name): TextInput
    {
        return TextInput::make($name)->label('Цена')->required()->inputMode('decimal')
            ->default('0.00')->helperText('В основных единицах валюты, до двух знаков после запятой.')
            ->rules(['regex:/^\d{1,10}(?:[.,]\d{1,2})?$/D'])
            ->formatStateUsing(fn ($state) => is_int($state) ? Money::decimal($state) : $state)
            ->dehydrateStateUsing(fn ($state): int => Money::parse((string) $state));
    }

    public static function currency(): Select
    {
        return Select::make('currency')->label('Валюта')->options(Money::CURRENCIES)->required()->default('RUB');
    }
}
