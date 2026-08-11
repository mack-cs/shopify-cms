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
            'collection_id' => 'Collection ID',
            'collection_title' => 'Collection Title',
            'collection_handle' => 'Collection Handle',
            'collection_url' => 'Collection URL',
            'collection_published_online' => 'Published on Online Store',
            'collection_online_publish_date' => 'Online Store Publication Date',
            'collection_publications' => 'Publication / Channel Information',
            'collection_product_count' => 'Collection Product Count',
            'collection_sort_order' => 'Collection Sort Order',
            'collection_updated_at' => 'Collection Updated At',
            'product_id' => 'Product ID',
            'product_title' => 'Product Title',
            'product_handle' => 'Product Handle',
            'product_url' => 'Product URL',
            'product_online_store_url' => 'Shopify Online Store URL',
            'product_status' => 'Product Status',
            'vendor' => 'Vendor',
            'product_type' => 'Product Type',
            'tags' => 'Tags',
            'total_inventory' => 'Total Inventory',
            'product_created_at' => 'Product Created At',
            'product_updated_at' => 'Product Updated At',
            'product_published_at' => 'Product Published At',
            'featured_image_url' => 'Featured Image URL',
            'product_category_name' => 'Product Category',
            'seo_title' => 'SEO Title',
            'seo_description' => 'SEO Description',
            'sku_summary' => 'SKU Summary',
            'variant_count' => 'Variant Count',
            'variants' => 'Variants',
        ];

        return collect($columns)->map(function (string $label, string $column): ExportColumn {
            $exportColumn = ExportColumn::make($column)->label($label);

            if ($column === 'tags') {
                return $exportColumn->formatStateUsing(fn ($state): string => collect((array) $state)->implode(', '));
            }
            if (in_array($column, ['collection_publications', 'variants'], true)) {
                return $exportColumn->formatStateUsing(fn ($state): string => json_encode($state ?: [], JSON_UNESCAPED_SLASHES));
            }

            return $exportColumn;
        })->values()->all();
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
