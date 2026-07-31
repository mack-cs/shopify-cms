<?php

namespace App\Filament\Resources;

use App\Enums\RolesEnum;
use App\Filament\Exports\ManagerProductMovementExporter;
use App\Filament\Resources\ManagerProductMovementResource\Pages;
use App\Models\ProductMovementReportRow;
use App\Models\ProductMovementReportRun;
use Filament\Actions\Exports\Enums\ExportFormat;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Actions\ExportAction;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Auth;

final class ManagerProductMovementResource extends Resource
{
    protected static ?string $model = ProductMovementReportRow::class;
    protected static ?string $navigationGroup = 'Shopify Sync';
    protected static ?string $navigationLabel = 'Manager Product Movement';
    protected static ?string $navigationIcon = 'heroicon-o-presentation-chart-line';
    protected static ?int $navigationSort = 5;

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
                TextColumn::make('vendor')->searchable()->sortable(),
                TextColumn::make('product_type')->label('Product type')->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('current_inventory')->label('Current Inventory')->numeric()->sortable(),
                TextColumn::make('current_inventory_status')
                    ->label('Inventory Status')
                    ->formatStateUsing(fn (?string $state): string => str((string) $state)->replace('_', ' ')->title()->toString())
                    ->badge()
                    ->toggleable(isToggledHiddenByDefault: true)
                    ->sortable(),
                TextColumn::make('currently_on_sale')
                    ->label('On Sale')
                    ->formatStateUsing(fn (bool $state): string => $state ? 'Yes' : 'No')
                    ->badge()
                    ->color(fn (bool $state): string => $state ? 'success' : 'gray')
                    ->sortable(),
                TextColumn::make('discount_percentage')
                    ->label('Discount %')
                    ->formatStateUsing(fn ($state, ProductMovementReportRow $record): string => $record->currently_on_sale && $state !== null
                        ? number_format((float) $state, 2) . '%'
                        : '-')
                    ->sortable(),
                TextColumn::make('net_units_sold')->label('Units Sold (Selected Period)')->numeric()->sortable(),
                TextColumn::make('average_units_per_month')->label('Average per Month')->numeric(2)->sortable(),
                TextColumn::make('last_sale_date')->label('Last Sale')->date()->placeholder('No sales')->sortable(),
                TextColumn::make('movement_classification')
                    ->label('Movement Category')
                    ->formatStateUsing(fn (string $state): string => self::movementLabel($state))
                    ->badge()
                    ->color(fn (string $state): string => self::movementColor($state))
                    ->sortable(),
                TextColumn::make('recommended_action')
                    ->label('Recommended Action')
                    ->formatStateUsing(fn (?string $state): string => self::actionLabel((string) $state))
                    ->badge()
                    ->color(fn (?string $state): string => self::actionColor((string) $state))
                    ->sortable(),
                TextColumn::make('manager_reason')->label('Reason')->wrap(),
            ])
            ->filters([
                SelectFilter::make('product_movement_report_run_id')
                    ->label('Reporting period')
                    ->options(fn (): array => self::runOptions())
                    ->default(fn (): ?int => self::latestRunId())
                    ->preload(),
                SelectFilter::make('movement_classification')
                    ->label('Movement category')
                    ->options(self::movementOptions()),
                SelectFilter::make('recommended_action')
                    ->label('Recommended action')
                    ->options(self::actionOptions()),
                SelectFilter::make('vendor')
                    ->options(fn (): array => self::distinctOptions('vendor'))
                    ->searchable()
                    ->preload(),
                SelectFilter::make('product_type')
                    ->label('Product type')
                    ->options(fn (): array => self::distinctOptions('product_type'))
                    ->searchable()
                    ->preload(),
                SelectFilter::make('current_inventory_status')
                    ->label('Stock')
                    ->options([
                        'in_stock' => 'In stock',
                        'out_of_stock' => 'Out of stock',
                        'untracked' => 'Untracked',
                        'unknown' => 'Unknown',
                    ]),
                TernaryFilter::make('currently_on_sale')->label('On sale'),
                SelectFilter::make('business_focus')
                    ->label('Quick filter')
                    ->options([
                        'restock' => 'Products to Restock',
                        'promote' => 'Products to Promote',
                        'no_sales' => 'Products With No Sales',
                        'excess_stock' => 'Products With Excess Stock',
                        'review' => 'Products Needing Review',
                    ])
                    ->query(function (Builder $query, array $data): Builder {
                        return match ($data['value'] ?? null) {
                            'restock' => $query->where('recommended_action', 'restock'),
                            'promote' => $query->where('recommended_action', 'promote'),
                            'no_sales' => $query->where('movement_classification', 'no_sales'),
                            'excess_stock' => $query
                                ->where('current_inventory', '>=', 10)
                                ->whereRaw('current_inventory > (average_units_per_month * 6)'),
                            'review' => $query->whereIn('recommended_action', ['review', 'insufficient_data']),
                            default => $query,
                        };
                    }),
            ])
            ->headerActions([
                ExportAction::make()
                    ->label('Export Manager Report')
                    ->icon('heroicon-o-document-arrow-down')
                    ->formats([ExportFormat::Csv, ExportFormat::Xlsx])
                    ->exporter(ManagerProductMovementExporter::class),
            ])
            ->actions([])
            ->bulkActions([]);
    }

    public static function canViewAny(): bool
    {
        return Auth::user()?->hasAnyRole([
            RolesEnum::SuperAdmin->value,
            RolesEnum::Admin->value,
        ]) ?? false;
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
            'index' => Pages\ListManagerProductMovements::route('/'),
        ];
    }

    public static function movementLabel(string $value): string
    {
        return self::movementOptions()[$value] ?? str($value)->replace('_', ' ')->title()->toString();
    }

    public static function actionLabel(string $value): string
    {
        return self::actionOptions()[$value] ?? str($value)->replace('_', ' ')->title()->toString();
    }

    public static function movementColor(string $value): string
    {
        return match ($value) {
            'fast_moving' => 'success',
            'medium_moving' => 'info',
            'slow_moving' => 'warning',
            'no_sales' => 'danger',
            'new_product' => 'primary',
            default => 'gray',
        };
    }

    public static function actionColor(string $value): string
    {
        return match ($value) {
            'restock' => 'success',
            'maintain' => 'info',
            'promote' => 'warning',
            'monitor' => 'primary',
            'review' => 'danger',
            default => 'gray',
        };
    }

    /**
     * @return array<string,string>
     */
    public static function movementOptions(): array
    {
        return [
            'fast_moving' => 'Fast Moving',
            'medium_moving' => 'Steady Moving',
            'slow_moving' => 'Slow Moving',
            'no_sales' => 'No Sales',
            'out_of_stock_or_unavailable' => 'Out of Stock',
            'new_product' => 'New Product',
            'insufficient_data' => 'Not Enough Data',
        ];
    }

    /**
     * @return array<string,string>
     */
    public static function actionOptions(): array
    {
        return [
            'restock' => 'Restock',
            'maintain' => 'Maintain',
            'promote' => 'Promote',
            'monitor' => 'Monitor',
            'review' => 'Review',
            'insufficient_data' => 'Insufficient Data',
        ];
    }

    /**
     * @return array<int|string,string>
     */
    private static function runOptions(): array
    {
        return ProductMovementReportRun::query()
            ->where('status', ProductMovementReportRun::STATUS_COMPLETED)
            ->latest('id')
            ->limit(25)
            ->get()
            ->mapWithKeys(fn (ProductMovementReportRun $run): array => [
                $run->id => "{$run->analysis_start_date->toDateString()} to {$run->analysis_end_date->toDateString()} ({$run->row_count} variants)",
            ])->all();
    }

    private static function latestRunId(): ?int
    {
        $id = ProductMovementReportRun::query()
            ->where('status', ProductMovementReportRun::STATUS_COMPLETED)
            ->latest('id')
            ->value('id');

        return $id === null ? null : (int) $id;
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
