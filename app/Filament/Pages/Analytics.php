<?php

namespace App\Filament\Pages;

use BackedEnum;

class Analytics extends SectionPage
{
    protected static ?string $title = 'Аналитика';

    protected static string|BackedEnum|null $navigationIcon = 'heroicon-o-chart-bar';

    protected static ?int $navigationSort = 50;

    protected static string $emptyHeading = 'Недостаточно данных для аналитики';

    protected static string $emptyDescription = 'После загрузки продаж здесь появятся отчёты о выручке, заказах и результатах работы маркетплейсов. Сейчас реальные данные отсутствуют.';
}
