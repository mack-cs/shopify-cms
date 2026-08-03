<?php

namespace App\Filament\Resources;

use App\Enums\RolesEnum;
use App\Filament\Exports\ProductMovementReportRowExporter;
use App\Filament\Resources\ProductMovementReportRowResource\Pages;
use App\Models\ProductMovementReportRow;
use App\Models\ProductMovementReportRun;
use Filament\Actions\Exports\Enums\ExportFormat;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Actions\ExportAction;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Table;
use Illuminate\Support\Facades\Auth;

final class ProductMovementReportRowResource extends Resource
{
    protected static ?string $model = ProductMovementReportRow::class;
    protected static ?string $navigationGroup = 'Shopify Sync';
    protected static ?string $navigationLabel = 'Detailed Product Movement Analysis';
    protected static ?string $navigationIcon = 'heroicon-o-chart-bar';
    protected static ?int $navigationSort = 6;

    public static function form(Form $form): Form
    {
        return $form->schema([]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->defaultSort('movement_score', 'desc')
            ->columns([
                TextColumn::make('product_title')->label('Product')->searchable()->wrap()->sortable(),
                TextColumn::make('variant_title')->label('Variant')->searchable()->toggleable(),
                TextColumn::make('sku')->searchable()->sortable(),
                TextColumn::make('movement_classification')
                    ->label('Movement')
                    ->badge()
                    ->formatStateUsing(fn (string $state): string => str($state)->replace('_', ' ')->title()->toString())
                    ->color(fn (string $state): string => match ($state) {
                        'fast_moving' => 'success',
                        'medium_moving' => 'info',
                        'slow_moving' => 'warning',
                        'no_sales' => 'danger',
                        'out_of_stock_or_unavailable' => 'gray',
                        default => 'gray',
                    })
                    ->sortable(),
                TextColumn::make('movement_score')->label('Score')->numeric(2)->sortable(),
                TextColumn::make('net_units_sold')->label('Net units')->numeric()->sortable(),
                TextColumn::make('average_units_per_month')->label('Avg/month')->numeric(2)->sortable(),
                TextColumn::make('sales_consistency_percentage')->label('Consistency')->suffix('%')->numeric(2)->sortable(),
                TextColumn::make('days_since_last_sale')->label('Days since sale')->numeric()->sortable(),
                TextColumn::make('current_inventory')->label('Inventory')->numeric()->sortable(),
                TextColumn::make('current_inventory_status')->label('Stock status')->badge()->sortable(),
                IconColumn::make('currently_on_sale')->label('On sale')->boolean()->sortable(),
                TextColumn::make('discount_percentage')->label('Discount')->suffix('%')->numeric(2)->toggleable(),
                TextColumn::make('vendor')->searchable()->sortable()->toggleable(),
                TextColumn::make('product_type')->label('Type')->searchable()->toggleable(),
                TextColumn::make('product_status')->badge()->sortable()->toggleable(),
                TextColumn::make('snapshot_days_available')->label('Snapshot days')->numeric()->toggleable(),
                TextColumn::make('in_stock_days')->label('In-stock days')->numeric()->toggleable(),
                TextColumn::make('units_sold_per_30_in_stock_days')->label('Per 30 in-stock')->numeric(2)->toggleable(),
                TextColumn::make('analysis_start_date')->date()->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('analysis_end_date')->date()->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('data_quality_note')->label('Data note')->wrap()->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                SelectFilter::make('product_movement_report_run_id')
                    ->label('Reporting period')
                    ->options(fn (): array => ProductMovementReportRun::query()
                        ->where('status', ProductMovementReportRun::STATUS_COMPLETED)
                        ->latest('id')
                        ->limit(25)
                        ->get()
                        ->mapWithKeys(fn (ProductMovementReportRun $run): array => [
                            $run->id => "{$run->analysis_start_date->toDateString()} to {$run->analysis_end_date->toDateString()} ({$run->row_count} variants)",
                        ])->all())
                    ->default(fn (): ?int => ProductMovementReportRun::query()
                        ->where('status', ProductMovementReportRun::STATUS_COMPLETED)
                        ->latest('id')
                        ->value('id'))
                    ->preload(),
                SelectFilter::make('movement_classification')
                    ->label('Movement')
                    ->options(self::classificationOptions()),
                SelectFilter::make('vendor')
                    ->options(fn (): array => self::distinctOptions('vendor'))
                    ->searchable()
                    ->preload(),
                SelectFilter::make('product_type')
                    ->label('Product type')
                    ->options(fn (): array => self::distinctOptions('product_type'))
                    ->searchable()
                    ->preload(),
                SelectFilter::make('product_status')
                    ->options(fn (): array => self::distinctOptions('product_status')),
                TernaryFilter::make('currently_on_sale')->label('Currently on sale'),
                SelectFilter::make('current_inventory_status')
                    ->label('Inventory status')
                    ->options([
                        'in_stock' => 'In stock',
                        'out_of_stock' => 'Out of stock',
                        'untracked' => 'Untracked',
                        'unknown' => 'Unknown',
                    ]),
                TernaryFilter::make('inventory_tracked')->label('Tracked inventory'),
                TernaryFilter::make('has_snapshot_history')->label('Snapshot history'),
            ])
            ->headerActions([
                ExportAction::make()
                    ->label('Export filtered report')
                    ->icon('heroicon-o-document-arrow-down')
                    ->authorize(fn (): bool => self::canViewAny())
                    ->formats([ExportFormat::Csv, ExportFormat::Xlsx])
                    ->exporter(ProductMovementReportRowExporter::class),
            ])
            ->actions([])
            ->bulkActions([]);
    }

    public static function canViewAny(): bool
    {
        return Auth::user()?->hasRole(RolesEnum::SuperAdmin->value) ?? false;
    }

    public static function canCreate(): bool
    {
        return false;
    }

    public static function canEdit($record): bool
    {
        return false;
    }

    public static function canDelete($record): bool
    {
        return false;
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListProductMovementReportRows::route('/'),
        ];
    }

    /**
     * @return array<string,string>
     */
    public static function classificationOptions(): array
    {
        return [
            'fast_moving' => 'Fast-moving',
            'medium_moving' => 'Medium-moving',
            'slow_moving' => 'Slow-moving',
            'no_sales' => 'No sales',
            'out_of_stock_or_unavailable' => 'Out of stock or unavailable',
            'new_product' => 'New product',
            'insufficient_data' => 'Insufficient data',
        ];
    }

    private static function distinctOptions(string $column): array
    {
        return ProductMovementReportRow::query()
            ->whereNotNull($column)
            ->where($column, '!=', '')
            ->distinct()
            ->orderBy($column)
            ->pluck($column, $column)
            ->all();
    }
}
