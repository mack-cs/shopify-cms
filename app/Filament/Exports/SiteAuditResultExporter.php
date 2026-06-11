<?php

namespace App\Filament\Exports;

use App\Models\SiteAuditResult;
use Filament\Actions\Exports\ExportColumn;
use Filament\Actions\Exports\Exporter;
use Filament\Actions\Exports\Models\Export;

class SiteAuditResultExporter extends Exporter
{
    protected static ?string $model = SiteAuditResult::class;

    public static function getColumns(): array
    {
        return [
            ExportColumn::make('id'),
            ExportColumn::make('site_audit_run_id'),
            ExportColumn::make('siteAuditRun.type')->label('Audit Type'),
            ExportColumn::make('siteAuditRun.status')->label('Audit Status'),
            ExportColumn::make('site_audit_url_id'),
            ExportColumn::make('siteAuditUrl.url')->label('URL'),
            ExportColumn::make('siteAuditUrl.resource_type')->label('Resource Type'),
            ExportColumn::make('status_code'),
            ExportColumn::make('result'),
            ExportColumn::make('final_url'),
            ExportColumn::make('response_time_ms')->label('Load Time Ms'),
            ExportColumn::make('speed_classification'),
            ExportColumn::make('error_reason'),
            ExportColumn::make('shopify_resource_status'),
            ExportColumn::make('shopify_context'),
            ExportColumn::make('error_message'),
            ExportColumn::make('created_at'),
            ExportColumn::make('updated_at'),
        ];
    }

    public static function getCompletedNotificationBody(Export $export): string
    {
        $body = 'Your site audit result export is ready to download.';

        if ($failedRowsCount = $export->getFailedRowsCount()) {
            $body .= " {$failedRowsCount} row(s) failed to export.";
        }

        return $body;
    }
}
