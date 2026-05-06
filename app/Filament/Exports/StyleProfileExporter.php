<?php

namespace App\Filament\Exports;

use App\Models\StyleProfile;
use Filament\Actions\Exports\Exporter;
use Filament\Actions\Exports\ExportColumn;
use Filament\Actions\Exports\Models\Export;

class StyleProfileExporter extends Exporter
{
    protected static ?string $model = StyleProfile::class;

    public static function getColumns(): array
    {
        return [
            ExportColumn::make('id'),
            ExportColumn::make('handle'),
            ExportColumn::make('sku'),
            ExportColumn::make('image_url'),
            ExportColumn::make('style_type'),
            ExportColumn::make('materials'),
            ExportColumn::make('components'),
            ExportColumn::make('colour_prompt'),
            ExportColumn::make('draft_title'),
            ExportColumn::make('draft_description'),
            ExportColumn::make('draft_seo_title'),
            ExportColumn::make('draft_seo_description'),
            ExportColumn::make('draft_image_alt_text'),
            ExportColumn::make('seo_updated_at'),
            ExportColumn::make('seo_updated_by'),
            ExportColumn::make('seo_approved_at'),
            ExportColumn::make('seo_approved_by'),
            ExportColumn::make('seo_approval_source'),
            ExportColumn::make('seo_approval_request_id'),
            ExportColumn::make('seo_sync_status'),
            ExportColumn::make('seo_synced_at'),
            ExportColumn::make('seo_synced_by'),
            ExportColumn::make('seo_sync_batch_id'),
            ExportColumn::make('applied_at'),
            ExportColumn::make('updated_at'),
        ];
    }

    public static function getCompletedNotificationBody(Export $export): string
    {
        $body = 'Your style profile export is ready to download.';

        if ($failedRowsCount = $export->getFailedRowsCount()) {
            $body .= " {$failedRowsCount} row(s) failed to export.";
        }

        return $body;
    }
}
