<?php

namespace App\Filament\Exports;

use App\Models\ShopifyCollectionProductReportRow;
use Filament\Actions\Exports\ExportColumn;
use Filament\Actions\Exports\Exporter;
use Filament\Actions\Exports\Models\Export;

final class ShopifyCollectionProductReportRowExporter extends Exporter
{
    protected static ?string $model = ShopifyCollectionProductReportRow::class;

    public static function getColumns(): array
    {
        $columns = [
            'collection_title' => 'Collection Title',
            'collection_handle' => 'Collection Handle',
            'collection_url' => 'Collection URL',
            'collection_sort_order' => 'Collection Sort Order',
            'product_title' => 'Product Title',
            'product_url' => 'Product URL',
            'product_status' => 'Product Status',
            'main_collection' => 'Main Collection',
            'product_type' => 'Product Type',
            'total_inventory' => 'Total Inventory',
            'product_created_at' => 'Product Created At',
            'run.completed_at' => 'Data Fetched From Shopify',
        ];

        return collect($columns)
            ->map(fn (string $label, string $column): ExportColumn => ExportColumn::make($column)->label($label))
            ->values()
            ->all();
    }

    public static function getCompletedNotificationBody(Export $export): string
    {
        $body = 'Your filtered Shopify collection product mapping is ready to download.';
        if ($failedRows = $export->getFailedRowsCount()) {
            $body .= " {$failedRows} row(s) failed to export.";
        }

        return $body;
    }
}
