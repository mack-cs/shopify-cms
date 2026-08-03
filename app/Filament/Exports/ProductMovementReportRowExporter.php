<?php

namespace App\Filament\Exports;

use App\Models\ProductMovementReportRow;
use Carbon\Carbon;
use Filament\Actions\Exports\ExportColumn;
use Filament\Actions\Exports\Exporter;
use Filament\Actions\Exports\Models\Export;

final class ProductMovementReportRowExporter extends Exporter
{
    protected static ?string $model = ProductMovementReportRow::class;

    public static function getColumns(): array
    {
        $columns = collect([
            'shopify_product_id' => 'Product ID',
            'shopify_variant_id' => 'Variant ID',
            'product_title' => 'Product title',
            'variant_title' => 'Variant title',
            'sku' => 'SKU',
            'vendor' => 'Vendor',
            'product_type' => 'Product type',
            'product_status' => 'Product status',
            'variant_status' => 'Variant status',
            'movement_product_kind' => 'Product role',
            'product_created_at' => 'Product created date',
            'analysis_start_date' => 'Analysis start date',
            'analysis_end_date' => 'Analysis end date',
            'months_analysed' => 'Months analysed',
            'direct_gross_units_sold' => 'Direct gross units sold',
            'direct_refunded_units' => 'Direct refunded units',
            'direct_net_units_sold' => 'Direct net units sold',
            'stack_attributed_gross_units' => 'Stack-attributed gross units',
            'stack_attributed_refunded_units' => 'Stack-attributed refunded units',
            'stack_attributed_net_units' => 'Stack-attributed net units',
            'contributing_stack_skus' => 'Contributing stack SKUs',
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
        ])->map(function (string $label, string $column): ExportColumn {
            $exportColumn = ExportColumn::make($column)->label($label);

            if ($column === 'contributing_stack_skus') {
                return $exportColumn->formatStateUsing(
                    fn ($state): string => collect((array) $state)->filter()->implode(', ')
                );
            }

            if ($column === 'movement_product_kind') {
                return $exportColumn->formatStateUsing(fn ($state): string => match ((string) $state) {
                    'stack' => 'Stack',
                    'component' => 'Stack Component',
                    default => 'Standard Product',
                });
            }

            return $exportColumn;
        })->values()->all();

        return [
            ExportColumn::make('run.completed_at')
                ->label('Report generated at')
                ->formatStateUsing(fn ($state): string => blank($state)
                    ? ''
                    : Carbon::parse($state)
                        ->timezone((string) config('product_movement.timezone', 'Africa/Johannesburg'))
                        ->format('d M Y H:i T')),
            ...$columns,
        ];
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
