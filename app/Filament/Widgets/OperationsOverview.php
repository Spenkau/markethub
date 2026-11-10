<?php

namespace App\Filament\Widgets;

use Filament\Schemas\Components\EmptyState;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Concerns\InteractsWithSchemas;
use Filament\Schemas\Contracts\HasSchemas;
use Filament\Schemas\Schema;
use Filament\Widgets\Widget;

class OperationsOverview extends Widget implements HasSchemas
{
    use InteractsWithSchemas;

    protected static bool $isLazy = false;

    protected int|string|array $columnSpan = 'full';

    protected string $view = 'filament.widgets.operations-overview';

    public function content(Schema $schema): Schema
    {
        return $schema->components([
            Grid::make(['default' => 1, 'lg' => 2])->schema([
                Section::make('Динамика продаж')->columnSpanFull()->schema([
                    EmptyState::make('Пока нет данных о продажах')
                        ->description('График появится после загрузки реальных продаж. Маркетплейсы ещё не подключены.')
                        ->icon('heroicon-o-chart-bar')->contained(false),
                ]),
                Section::make('Последние заказы')->schema([
                    EmptyState::make('Заказов пока нет')
                        ->description('Новые заказы появятся здесь после подключения маркетплейсов.')
                        ->icon('heroicon-o-shopping-bag')->contained(false),
                ]),
                Section::make('Состояние интеграций')->schema([
                    EmptyState::make('Нет активных интеграций')
                        ->description('Wildberries — не подключён. Ozon — не подключён. Настройка подключений будет доступна позже.')
                        ->icon('heroicon-o-building-storefront')->contained(false),
                ]),
            ]),
        ]);
    }
}
