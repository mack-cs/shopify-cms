<?php

namespace App\Filament\Exports;

use App\Models\Blog;
use Filament\Actions\Exports\Exporter;
use Filament\Actions\Exports\ExportColumn;
use Filament\Actions\Exports\Models\Export;

class BlogExporter extends Exporter
{
    protected static ?string $model = Blog::class;

    public static function getColumns(): array
    {
        return [
            ExportColumn::make('id'),
            ExportColumn::make('title'),
            ExportColumn::make('slug'),
            ExportColumn::make('status'),
            ExportColumn::make('author.name')->label('Author'),
            ExportColumn::make('published_at'),
            ExportColumn::make('excerpt'),
            ExportColumn::make('keyword_focus'),
            ExportColumn::make('seo_title'),
            ExportColumn::make('meta_title'),
            ExportColumn::make('meta_description'),
            ExportColumn::make('reading_time_minutes'),
            ExportColumn::make('created_at'),
            ExportColumn::make('updated_at'),
        ];
    }

    public static function getCompletedNotificationBody(Export $export): string
    {
        $body = 'Your blog export is ready to download.';

        if ($failedRowsCount = $export->getFailedRowsCount()) {
            $body .= " {$failedRowsCount} row(s) failed to export.";
        }

        return $body;
    }
}
