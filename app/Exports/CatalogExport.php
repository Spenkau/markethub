<?php

namespace App\Exports;

use Illuminate\Database\Eloquent\Builder;
use Maatwebsite\Excel\Concerns\FromQuery;
use Maatwebsite\Excel\Concerns\WithColumnFormatting;
use Maatwebsite\Excel\Concerns\WithCustomValueBinder;
use Maatwebsite\Excel\Concerns\WithHeadings;
use Maatwebsite\Excel\Concerns\WithMapping;
use Maatwebsite\Excel\Concerns\WithStrictNullComparison;
use Maatwebsite\Excel\Concerns\WithStyles;
use Maatwebsite\Excel\DefaultValueBinder;
use PhpOffice\PhpSpreadsheet\Cell\Cell;
use PhpOffice\PhpSpreadsheet\Cell\DataType;
use PhpOffice\PhpSpreadsheet\Shared\Date;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;

class CatalogExport extends DefaultValueBinder implements FromQuery, WithColumnFormatting, WithCustomValueBinder, WithHeadings, WithMapping, WithStrictNullComparison, WithStyles
{
    public function __construct(private Builder $builder, private bool $stocks = false) {}

    public function query(): Builder
    {
        return clone $this->builder;
    }

    public function headings(): array
    {
        return $this->stocks
            ? ['ID', 'Товар', 'SKU', 'Маркетплейс', 'Аккаунт', 'Внешний ID', 'Внешний SKU', 'Остаток', 'Цена', 'Валюта', 'Статус', 'Дата создания', 'Дата обновления', 'Локация', 'Происхождение', 'Актуальность', 'Отслеживание', 'Время снимка']
            : ['ID', 'Название', 'SKU', 'Описание', 'Цена', 'Валюта', 'Статус', 'Размещения', 'Дата создания', 'Дата обновления'];
    }

    public function map(mixed $row): array
    {
        $dates = [Date::dateTimeToExcel($row->created_at), Date::dateTimeToExcel($row->updated_at)];

        return $this->stocks
            ? [$row->id, $row->product?->name ?? $row->marketplaceProduct?->title, $row->product?->sku ?? $row->external_sku, match ($row->marketplaceAccount?->marketplace) {
                'shopify' => 'Shopify', 'ozon' => 'Ozon', default => 'Wildberries'
            }, $row->marketplaceAccount?->name, $row->external_id, $row->external_sku, $row->stock_quantity, $row->price_minor / 100, $row->currency, $row->status === 'active' ? 'Активно' : 'Неактивно', ...$dates, $row->location_name, $row->origin === 'demo' ? 'Демо' : ($row->origin === 'imported' ? 'Внешний снимок' : 'Размещение'), $row->is_current === null ? null : ($row->is_current ? 'Актуален' : 'Устарел'), $row->tracked === null ? null : ($row->tracked ? 'Да' : 'Нет'), $row->inventory_synced_at]
            : [$row->id, $row->name, $row->sku, $row->description, $row->base_price_minor / 100, $row->currency, $row->is_active ? 'Активен' : 'Неактивен', $row->listings_count, ...$dates];
    }

    public function bindValue(Cell $cell, mixed $value): bool
    {
        // Treat every string as literal text, including formulas and numeric SKUs.
        if (is_string($value)) {
            $cell->setValueExplicit($value, DataType::TYPE_STRING);

            return true;
        }

        return parent::bindValue($cell, $value);
    }

    public function columnFormats(): array
    {
        return $this->stocks
            ? ['I' => '#,##0.00', 'L' => 'dd.mm.yyyy hh:mm', 'M' => 'dd.mm.yyyy hh:mm']
            : ['E' => '#,##0.00', 'I' => 'dd.mm.yyyy hh:mm', 'J' => 'dd.mm.yyyy hh:mm'];
    }

    public function styles(Worksheet $sheet): ?array
    {
        $sheet->freezePane('A2');
        $sheet->setAutoFilter($sheet->calculateWorksheetDimension());
        foreach (range('A', $this->stocks ? 'R' : 'J') as $column) {
            $sheet->getColumnDimension($column)->setWidth(22);
        }

        return [1 => ['font' => ['bold' => true]]];
    }
}
