<?php

namespace App\Filament\Exports;

use App\Models\SiteAuditUrl;
use Filament\Actions\Exports\ExportColumn;
use Filament\Actions\Exports\Exporter;
use Filament\Actions\Exports\Models\Export;

class SiteAuditUrlExporter extends Exporter
{
    protected static ?string $model = SiteAuditUrl::class;

    public static function getColumns(): array
    {
        return [
            ExportColumn::make('id'),
            ExportColumn::make('url'),
            ExportColumn::make('source'),
            ExportColumn::make('sitemap_url'),
            ExportColumn::make('resource_type'),
            ExportColumn::make('is_active'),
            ExportColumn::make('last_seen_at'),
            ExportColumn::make('last_checked_at'),
            ExportColumn::make('latestResult.result')->label('Latest Result'),
            ExportColumn::make('latestResult.status_code')->label('Latest Status Code'),
            ExportColumn::make('latestResult.final_url')->label('Latest Final URL'),
            ExportColumn::make('latestResult.response_time_ms')->label('Latest Load Time Ms'),
            ExportColumn::make('latestResult.speed_classification')->label('Latest Speed'),
            ExportColumn::make('latestResult.error_reason')->label('Latest Reason'),
            ExportColumn::make('latestResult.shopify_resource_status')->label('Latest Shopify Status'),
            ExportColumn::make('latestResult.error_message')->label('Latest Error'),
            ExportColumn::make('created_at'),
            ExportColumn::make('updated_at'),
        ];
    }

    public static function getCompletedNotificationBody(Export $export): string
    {
        $body = 'Your site audit URL export is ready to download.';

        if ($failedRowsCount = $export->getFailedRowsCount()) {
            $body .= " {$failedRowsCount} row(s) failed to export.";
        }

        return $body;
    }
}
