<?php

namespace App\Filament\Resources\MarketplaceAccounts\Schemas;

use App\Models\MarketplaceAccount;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Schema;

class MarketplaceAccountForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                TextInput::make('name')->label('Название подключения')->required()->maxLength(255),
                Select::make('marketplace')->label('Маркетплейс')->options(MarketplaceAccount::MARKETPLACES)->required(),
                Select::make('status')->label('Статус')->options(MarketplaceAccount::STATUSES)->required()->default('demo'),
                Toggle::make('is_demo')->label('Демонстрационное подключение')->default(true)->required(),
            ]);
    }
}
