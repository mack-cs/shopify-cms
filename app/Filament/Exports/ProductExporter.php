<?php

namespace App\Filament\Exports;

use App\Models\Product;
use Filament\Actions\Exports\Exporter;
use Filament\Actions\Exports\ExportColumn;
use Filament\Actions\Exports\Models\Export;

class ProductExporter extends Exporter
{
    protected static ?string $model = Product::class;

    public static function getColumns(): array
    {
        return [
            ExportColumn::make('id'),
            ExportColumn::make('handle'),
            ExportColumn::make('title'),
            ExportColumn::make('vendor'),
            ExportColumn::make('type'),
            ExportColumn::make('product_category'),
            ExportColumn::make('google_product_category'),
            ExportColumn::make('tags'),
            ExportColumn::make('published'),
            ExportColumn::make('status'),
            ExportColumn::make('seo_title'),
            ExportColumn::make('seo_description'),
            ExportColumn::make('batch'),
            ExportColumn::make('is_bundle'),
            ExportColumn::make('you_save'),
            ExportColumn::make('created_at'),
            ExportColumn::make('updated_at'),
        ];
    }

    public static function getCompletedNotificationBody(Export $export): string
    {
        $body = 'Your product export is ready to download.';

        if ($failedRowsCount = $export->getFailedRowsCount()) {
            $body .= " {$failedRowsCount} row(s) failed to export.";
        }

        return $body;
    }
}
