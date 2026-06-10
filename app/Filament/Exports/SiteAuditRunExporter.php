<?php

namespace App\Filament\Exports;

use App\Models\SiteAuditRun;
use Filament\Actions\Exports\ExportColumn;
use Filament\Actions\Exports\Exporter;
use Filament\Actions\Exports\Models\Export;

class SiteAuditRunExporter extends Exporter
{
    protected static ?string $model = SiteAuditRun::class;

    public static function getColumns(): array
    {
        return [
            ExportColumn::make('id'),
            ExportColumn::make('type'),
            ExportColumn::make('status'),
            ExportColumn::make('total_urls'),
            ExportColumn::make('checked_urls'),
            ExportColumn::make('failed_urls'),
            ExportColumn::make('started_at'),
            ExportColumn::make('completed_at'),
            ExportColumn::make('error_message'),
            ExportColumn::make('created_at'),
            ExportColumn::make('updated_at'),
        ];
    }

    public static function getCompletedNotificationBody(Export $export): string
    {
        $body = 'Your site audit run export is ready to download.';

        if ($failedRowsCount = $export->getFailedRowsCount()) {
            $body .= " {$failedRowsCount} row(s) failed to export.";
        }

        return $body;
    }
}
