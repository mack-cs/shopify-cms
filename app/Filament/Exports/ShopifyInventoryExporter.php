<?php

namespace App\Filament\Exports;

use App\Models\ProductInventoryEvent;
use Filament\Actions\Exports\ExportColumn;
use Filament\Actions\Exports\Exporter;
use Filament\Actions\Exports\Models\Export;

class ShopifyInventoryExporter extends Exporter
{
    protected static ?string $model = ProductInventoryEvent::class;

    public static function getColumns(): array
    {
        return [
            ExportColumn::make('product_id'),
            ExportColumn::make('product_inventory_snapshot_id'),
            ExportColumn::make('previous_product_inventory_snapshot_id'),
            ExportColumn::make('observed_by'),
            ExportColumn::make('product_title'),
            ExportColumn::make('product_handle'),
            ExportColumn::make('product_shopify_id'),
            ExportColumn::make('event_type'),
            ExportColumn::make('occurred_at'),
            ExportColumn::make('source'),
            ExportColumn::make('from_is_sellable'),
            ExportColumn::make('to_is_sellable'),
            ExportColumn::make('from_is_out_of_stock'),
            ExportColumn::make('to_is_out_of_stock'),
            ExportColumn::make('from_status'),
            ExportColumn::make('to_status'),
            ExportColumn::make('from_reason'),
            ExportColumn::make('to_reason'),
            ExportColumn::make('metadata')
        ];
    }

    public static function getCompletedNotificationBody(Export $export): string
    {
        $body = 'Your collections export is ready to download.';

        if ($failedRowsCount = $export->getFailedRowsCount()) {
            $body .= " {$failedRowsCount} row(s) failed to export.";
        }

        return $body;
    }
}
