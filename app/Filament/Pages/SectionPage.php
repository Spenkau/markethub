<?php

namespace App\Filament\Pages;

use Filament\Pages\Page;
use Filament\Schemas\Components\EmptyState;
use Filament\Schemas\Schema;

abstract class SectionPage extends Page
{
    protected static string $emptyHeading;

    protected static string $emptyDescription;

    public function content(Schema $schema): Schema
    {
        return $schema->components([
            EmptyState::make(static::$emptyHeading)
                ->description(static::$emptyDescription)
                ->icon(static::getNavigationIcon()),
        ]);
    }
}
