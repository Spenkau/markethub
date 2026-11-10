<?php

namespace App\Filament\Pages;

use App\Filament\Widgets\BusinessStats;
use App\Filament\Widgets\OperationsOverview;
use Filament\Pages\Dashboard as BaseDashboard;

class Dashboard extends BaseDashboard
{
    protected static ?string $title = 'Дашборд';

    protected ?string $subheading = 'Обзор вашего бизнеса на маркетплейсах';

    public function getWidgets(): array
    {
        return [BusinessStats::class, OperationsOverview::class];
    }
}
