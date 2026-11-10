<?php

namespace App\Filament\Pages;

use BackedEnum;

class Settings extends SectionPage
{
    protected static ?string $title = 'Настройки';

    protected static string|BackedEnum|null $navigationIcon = 'heroicon-o-cog-6-tooth';

    protected static ?int $navigationSort = 60;

    protected static string $emptyHeading = 'Настройки будут доступны позже';

    protected static string $emptyDescription = 'Здесь появятся параметры приложения и синхронизации. Светлую или тёмную тему уже можно выбрать в меню пользователя.';
}
