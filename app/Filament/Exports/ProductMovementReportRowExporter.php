<?php

namespace App\Filament\Exports;

use App\Models\ProductMovementReportRow;
use Filament\Actions\Exports\ExportColumn;
use Filament\Actions\Exports\Exporter;
use Filament\Actions\Exports\Models\Export;

final class ProductMovementReportRowExporter extends Exporter
{
    protected static ?string $model = ProductMovementReportRow::class;

    public static function getColumns(): array
    {
        return collect([
            'shopify_product_id' => 'Product ID',
            'shopify_variant_id' => 'Variant ID',
            'product_title' => 'Product title',
            'variant_title' => 'Variant title',
            'sku' => 'SKU',
            'vendor' => 'Vendor',
            'product_type' => 'Product type',
            'product_status' => 'Product status',
            'variant_status' => 'Variant status',
            'product_created_at' => 'Product created date',
            'analysis_start_date' => 'Analysis start date',
            'analysis_end_date' => 'Analysis end date',
            'months_analysed' => 'Months analysed',
            'gross_units_sold' => 'Gross units sold',
            'refunded_units' => 'Refunded units',
            'net_units_sold' => 'Net units sold',
            'order_count' => 'Order count',
            'average_units_per_month' => 'Average units sold per month',
            'average_units_per_30_days' => 'Average units sold per 30 days',
            'months_with_sales' => 'Months with sales',
            'sales_consistency_percentage' => 'Sales consistency percentage',
            'first_sale_date' => 'First sale date',
            'last_sale_date' => 'Last sale date',
            'days_since_last_sale' => 'Days since last sale',
            'first_inventory_snapshot_date' => 'First inventory snapshot date',
            'snapshot_days_available' => 'Snapshot days available',
            'in_stock_days' => 'In-stock days',
            'out_of_stock_days' => 'Out-of-stock days',
            'units_sold_per_30_in_stock_days' => 'Units sold per 30 in-stock days',
            'opening_snapshot_inventory' => 'Opening snapshot inventory',
            'average_snapshot_inventory' => 'Average snapshot inventory',
            'closing_snapshot_inventory' => 'Closing snapshot inventory',
            'current_inventory' => 'Current inventory',
            'inventory_tracked' => 'Inventory tracked',
            'current_inventory_status' => 'Current inventory status',
            'movement_score' => 'Movement score',
            'movement_classification' => 'Movement classification',
            'currently_on_sale' => 'Currently on sale',
            'current_price' => 'Current price',
            'compare_at_price' => 'Compare-at price',
            'discount_percentage' => 'Discount percentage',
            'has_snapshot_history' => 'Has snapshot history',
            'data_quality_note' => 'Data quality or classification note',
        ])->map(
            fn (string $label, string $column): ExportColumn => ExportColumn::make($column)->label($label)
        )->values()->all();
    }

    public static function getCompletedNotificationBody(Export $export): string
    {
        $body = 'Your filtered product movement report is ready to download.';
        if ($failedRows = $export->getFailedRowsCount()) {
            $body .= " {$failedRows} row(s) failed to export.";
        }

        return $body;
    }
}
