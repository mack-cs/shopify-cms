<?php

namespace App\Filament\Exports;

use App\Models\DropdownOption;
use Filament\Actions\Exports\ExportColumn;
use Filament\Actions\Exports\Exporter;
use Filament\Actions\Exports\Models\Export;

class DropdownOptionExporter extends Exporter
{
    protected static ?string $model = DropdownOption::class;

    public static function getColumns(): array
    {
        return [
            ExportColumn::make('header')->label('Dropdown'),
            ExportColumn::make('value'),
            ExportColumn::make('collection_style')->label('Collection'),
            ExportColumn::make('collection_tag_primary')->label('Collection tag 1'),
            ExportColumn::make('collection_tag_secondary')->label('Collection tag 2'),
            ExportColumn::make('vendor'),
            ExportColumn::make('product_type')->label('Product type'),
            ExportColumn::make('active'),
            ExportColumn::make('sort_order'),
            ExportColumn::make('updated_at'),
        ];
    }

    public static function getCompletedNotificationBody(Export $export): string
    {
        $body = 'Your dropdown values export is ready to download.';

        if ($failedRowsCount = $export->getFailedRowsCount()) {
            $body .= " {$failedRowsCount} row(s) failed to export.";
        }

        return $body;
    }
}
