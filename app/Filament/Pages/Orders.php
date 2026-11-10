<?php

namespace App\Filament\Pages;

use BackedEnum;

class Orders extends SectionPage
{
    protected static ?string $title = 'Заказы';

    protected static string|BackedEnum|null $navigationIcon = 'heroicon-o-shopping-bag';

    protected static ?int $navigationSort = 10;

    protected static string $emptyHeading = 'Заказов пока нет';

    protected static string $emptyDescription = 'Здесь появятся заказы Wildberries и Ozon, их статусы и история обработки. Подключение маркетплейсов будет доступно на следующем этапе.';
}
