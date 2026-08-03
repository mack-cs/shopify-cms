<?php

namespace App\Filament\Exports;

use App\Filament\Resources\ManagerProductMovementResource;
use App\Models\ProductMovementReportRow;
use Carbon\Carbon;
use Filament\Actions\Exports\ExportColumn;
use Filament\Actions\Exports\Exporter;
use Filament\Actions\Exports\Models\Export;

final class ManagerProductMovementExporter extends Exporter
{
    protected static ?string $model = ProductMovementReportRow::class;

    public static function getColumns(): array
    {
        return [
            ExportColumn::make('run.completed_at')
                ->label('Report Generated At')
                ->formatStateUsing(fn ($state): string => blank($state)
                    ? ''
                    : Carbon::parse($state)
                        ->timezone((string) config('product_movement.timezone', 'Africa/Johannesburg'))
                        ->format('d M Y H:i T')),
            ExportColumn::make('product_title')->label('Product title'),
            ExportColumn::make('variant_title')->label('Variant title'),
            ExportColumn::make('sku')->label('SKU'),
            ExportColumn::make('vendor')->label('Vendor'),
            ExportColumn::make('product_status')
                ->label('Product Status')
                ->formatStateUsing(fn ($state): string => str((string) $state)->title()->toString()),
            ExportColumn::make('movement_product_kind')
                ->label('Product Role')
                ->formatStateUsing(fn ($state): string => match ((string) $state) {
                    'stack' => 'Stack',
                    'component' => 'Stack Component',
                    default => 'Standard Product',
                }),
            ExportColumn::make('product_type')->label('Product type'),
            ExportColumn::make('current_inventory')->label('Current inventory'),
            ExportColumn::make('currently_on_sale')
                ->label('On Sale')
                ->formatStateUsing(fn ($state): string => $state ? 'Yes' : 'No'),
            ExportColumn::make('discount_percentage')
                ->label('Discount %')
                ->formatStateUsing(fn ($state, ProductMovementReportRow $record): string => $record->currently_on_sale && $state !== null
                    ? number_format((float) $state, 2) . '%'
                    : '-'),
            ExportColumn::make('direct_net_units_sold')->label('Direct Units Sold'),
            ExportColumn::make('stack_attributed_net_units')->label('Sold Through Stacks'),
            ExportColumn::make('net_units_sold')->label('Total Demand Units'),
            ExportColumn::make('contributing_stack_skus')
                ->label('Contributing Stack SKUs')
                ->formatStateUsing(fn ($state): string => collect((array) $state)->filter()->implode(', ')),
            ExportColumn::make('average_units_per_month')
                ->label('Average Units Sold per Month')
                ->formatStateUsing(fn ($state): string => number_format((float) $state, 1)),
            ExportColumn::make('last_sale_date')
                ->label('Last Sale Date')
                ->formatStateUsing(fn ($state): string => blank($state)
                    ? ''
                    : Carbon::parse($state)->format('d M Y')),
            ExportColumn::make('movement_classification')
                ->label('Movement Category')
                ->formatStateUsing(fn ($state): string => ManagerProductMovementResource::movementLabel((string) $state)),
            ExportColumn::make('recommended_action')
                ->label('Recommended Action')
                ->formatStateUsing(fn ($state): string => ManagerProductMovementResource::actionLabel((string) $state)),
            ExportColumn::make('manager_reason')->label('Reason'),
        ];
    }

    public static function getCompletedNotificationBody(Export $export): string
    {
        $body = 'Your filtered manager product movement report is ready to download.';
        if ($failedRows = $export->getFailedRowsCount()) {
            $body .= " {$failedRows} row(s) failed to export.";
        }

        return $body;
    }
}
