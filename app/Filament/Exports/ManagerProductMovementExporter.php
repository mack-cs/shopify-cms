<?php

namespace App\Filament\Exports;

use App\Filament\Resources\ManagerProductMovementResource;
use App\Models\ProductMovementReportRow;
use Filament\Actions\Exports\ExportColumn;
use Filament\Actions\Exports\Exporter;
use Filament\Actions\Exports\Models\Export;

final class ManagerProductMovementExporter extends Exporter
{
    protected static ?string $model = ProductMovementReportRow::class;

    public static function getColumns(): array
    {
        return [
            ExportColumn::make('product_title')->label('Product title'),
            ExportColumn::make('variant_title')->label('Variant title'),
            ExportColumn::make('sku')->label('SKU'),
            ExportColumn::make('vendor')->label('Vendor'),
            ExportColumn::make('product_type')->label('Product type'),
            ExportColumn::make('current_inventory')->label('Current inventory'),
            ExportColumn::make('currently_on_sale')
                ->label('On Sale')
                ->formatStateUsing(fn ($state): string => $state ? 'Yes' : 'No'),
            ExportColumn::make('discount_percentage')
                ->label('Discount %')
                ->formatStateUsing(fn ($state, ProductMovementReportRow $record): string => $record->currently_on_sale && $state !== null
                    ? number_format((float) $state, 2) . '%'
                    : '-'),
            ExportColumn::make('net_units_sold')->label('Units Sold (Selected Period)'),
            ExportColumn::make('average_units_per_month')->label('Average Units Sold per Month'),
            ExportColumn::make('last_sale_date')->label('Last Sale Date'),
            ExportColumn::make('movement_classification')
                ->label('Movement Category')
                ->formatStateUsing(fn ($state): string => ManagerProductMovementResource::movementLabel((string) $state)),
            ExportColumn::make('recommended_action')
                ->label('Recommended Action')
                ->formatStateUsing(fn ($state): string => ManagerProductMovementResource::actionLabel((string) $state)),
            ExportColumn::make('manager_reason')->label('Reason'),
        ];
    }

    public static function getCompletedNotificationBody(Export $export): string
    {
        $body = 'Your filtered manager product movement report is ready to download.';
        if ($failedRows = $export->getFailedRowsCount()) {
            $body .= " {$failedRows} row(s) failed to export.";
        }

        return $body;
    }
}
