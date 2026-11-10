<?php

namespace App\Filament\Widgets;

use App\Models\MarketplaceAccount;
use App\Models\Product;
use Filament\Widgets\StatsOverviewWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;

class BusinessStats extends StatsOverviewWidget
{
    protected static bool $isLazy = false;

    protected ?string $pollingInterval = null;

    protected function getStats(): array
    {
        return [
            Stat::make('Общая выручка', '0 ₽')->description('Продажи ещё не загружены')->icon('heroicon-o-banknotes'),
            Stat::make('Количество заказов', '0')->description('Заказов пока нет')->icon('heroicon-o-shopping-bag'),
            Stat::make('Количество товаров', (string) Product::count())->description('В активной организации')->icon('heroicon-o-cube'),
            Stat::make('Активные маркетплейсы', (string) MarketplaceAccount::where('status', 'active')->where('is_demo', false)->count())->description('Реальные активные подключения')->icon('heroicon-o-building-storefront'),
        ];
    }
}
