<?php

namespace App\Filament\Pages;

use BackedEnum;

class Products extends SectionPage
{
    protected static ?string $title = 'Товары';

    protected static string|BackedEnum|null $navigationIcon = 'heroicon-o-cube';

    protected static ?int $navigationSort = 20;

    protected static string $emptyHeading = 'Каталог пока пуст';

    protected static string $emptyDescription = 'Здесь будет единый каталог товаров: карточки, артикулы и связь с маркетплейсами. Данные появятся после реализации интеграций.';
}
