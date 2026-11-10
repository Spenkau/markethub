<?php

namespace App\Filament\Pages;

use BackedEnum;

class Inventory extends SectionPage
{
    protected static ?string $title = 'Остатки';

    protected static string|BackedEnum|null $navigationIcon = 'heroicon-o-archive-box';

    protected static ?int $navigationSort = 30;

    protected static string $emptyHeading = 'Нет данных об остатках';

    protected static string $emptyDescription = 'Здесь появятся остатки товаров по складам и информация о доступности. Учёт запасов будет добавлен на следующем этапе.';
}
