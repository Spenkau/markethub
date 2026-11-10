<?php

namespace App\Filament\Pages;

use BackedEnum;

class Marketplaces extends SectionPage
{
    protected static ?string $title = 'Маркетплейсы';

    protected static string|BackedEnum|null $navigationIcon = 'heroicon-o-building-storefront';

    protected static ?int $navigationSort = 40;

    protected static string $emptyHeading = 'Маркетплейсы не подключены';

    protected static string $emptyDescription = 'Здесь будут подключения Wildberries и Ozon, параметры доступа и состояние синхронизации. API-интеграции пока не реализованы.';
}
