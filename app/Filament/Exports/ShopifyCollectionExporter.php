<?php

namespace App\Filament\Exports;

use App\Models\ShopifyCollection;
use Filament\Actions\Exports\Exporter;
use Filament\Actions\Exports\ExportColumn;
use Filament\Actions\Exports\Models\Export;

class ShopifyCollectionExporter extends Exporter
{
    protected static ?string $model = ShopifyCollection::class;

    public static function getColumns(): array
    {
        return [
            ExportColumn::make('id'),
            ExportColumn::make('import_id'),
            ExportColumn::make('shopify_id'),
            ExportColumn::make('handle'),
            ExportColumn::make('title'),
            ExportColumn::make('description_html'),
            ExportColumn::make('seo_title'),
            ExportColumn::make('seo_description'),
            ExportColumn::make('footer_title'),
            ExportColumn::make('elegant_footer_description'),
            ExportColumn::make('deindex'),
            ExportColumn::make('published_on_online_store_only'),
            ExportColumn::make('published_channel_names'),
            ExportColumn::make('draft_title'),
            ExportColumn::make('draft_description_html'),
            ExportColumn::make('draft_seo_title'),
            ExportColumn::make('draft_seo_description'),
            ExportColumn::make('draft_footer_title'),
            ExportColumn::make('draft_elegant_footer_description'),
            ExportColumn::make('batch'),
            ExportColumn::make('sync_status'),
            ExportColumn::make('last_synced_at'),
            ExportColumn::make('created_at'),
            ExportColumn::make('updated_at'),
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
