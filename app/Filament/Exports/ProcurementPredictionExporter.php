<?php

namespace App\Filament\Exports;

use App\Models\ProcurementPrediction;
use Filament\Actions\Exports\ExportColumn;
use Filament\Actions\Exports\Exporter;
use Filament\Actions\Exports\Models\Export;

final class ProcurementPredictionExporter extends Exporter
{
    protected static ?string $model = ProcurementPrediction::class;

    public static function getColumns(): array
    {
        $columns = [
            'sku' => 'SKU', 'product_name' => 'Product', 'variant_name' => 'Variant',
            'vendor' => 'Vendor', 'main_collection' => 'Main Collection', 'product_type' => 'Product Type',
            'shopify_product_id' => 'Shopify Product ID', 'shopify_variant_id' => 'Shopify Variant ID',
            'cms_movement_classification' => 'CMS Movement Classification',
            'ml_movement_classification' => 'ML Movement Classification',
            'movement_classification_matches' => 'Movement Classifications Match',
            'movement_score' => 'Movement Score', 'sales_consistency' => 'Sales Consistency %',
            'net_units_sold' => 'Net Units Sold', 'average_weekly_demand' => 'Average Weekly Demand',
            'units_sold_per_30_in_stock_days' => 'Units Sold per 30 In-stock Days',
            'ml_predicted_weekly_demand' => 'ML Predicted Weekly Demand',
            'weighted_predicted_weekly_demand' => 'Weighted Predicted Weekly Demand',
            'predicted_weekly_demand' => 'Selected Predicted Weekly Demand',
            'selected_prediction_method' => 'Selected Prediction Method',
            'current_inventory' => 'Current Inventory',
            'action_status' => 'Action Required',
            'ignore' => 'Ignore',
            'total_quantity_on_order' => 'Total Quantity On Order',
            'procurement_actioned' => 'Procurement Actioned',
            'projected_inventory_position' => 'Projected Inventory Position',
            'in_stock_days' => 'In-stock Days',
            'out_of_stock_days' => 'Out-of-stock Days',
            'attention_horizon_days' => 'Attention Horizon Days',
            'lead_time_days_used' => 'Lead Time Days Used', 'lead_time_source' => 'Lead Time Source',
            'estimated_days_of_stock_remaining' => 'Estimated Days of Stock Remaining',
            'predicted_runout_date' => 'Predicted Runout Date',
            'stock_required_for_attention_horizon' => 'Stock Required for Attention Horizon',
            'stock_required_for_lead_time' => 'Stock Required for Lead Time',
            'recommended_order_before_incoming_stock' => 'Recommended Order Before Incoming Stock',
            'additional_order_required' => 'Additional Order Required',
            'incoming_stock_covers_requirement' => 'Incoming Stock Covers Requirement',
            'stockout_before_incoming_arrival' => 'Stockout Before Incoming Arrival',
            'preliminary_order_quantity' => 'Preliminary Order Quantity',
            'currently_on_sale' => 'Currently on Sale', 'sale_percentage' => 'Sale Percentage',
            'action_reason' => 'Action Reason', 'data_quality_status' => 'Data-quality Status',
            'data_quality_warning' => 'Data-quality Warning', 'generated_at' => 'Prediction Generated At',
            'run.run_uuid' => 'Run UUID', 'run.calculation_date' => 'Calculation Date',
            'run.product_movement_generated_at' => 'Product Movement Generated At',
            'run.product_movement_source_version' => 'Product Movement Source Version',
            'run.model_version' => 'Model Version', 'run.default_lead_time_days' => 'Run Default Lead Time Days',
            'run.attention_horizon_days' => 'Run Attention Horizon Days',
        ];

        return collect($columns)->map(
            fn (string $label, string $column): ExportColumn => ExportColumn::make($column)->label($label)
        )->values()->all();
    }

    public function getFileName(Export $export): string
    {
        return 'procurement-predictions-'.now()->format('Y-m-d');
    }

    public static function getCompletedNotificationBody(Export $export): string
    {
        return "Your procurement prediction export is ready with {$export->successful_rows} row(s).";
    }
}
