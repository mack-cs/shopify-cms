<?php

namespace App\Filament\Resources;

use App\Enums\PermissionEnum;
use App\Filament\Exports\ProcurementPredictionExporter;
use App\Filament\Resources\ProcurementPredictionResource\Pages;
use App\Models\ProcurementPrediction;
use App\Models\ProcurementPredictionRun;
use Filament\Actions\Exports\Enums\ExportFormat;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables\Actions\ExportAction;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Auth;

final class ProcurementPredictionResource extends Resource
{
    protected static ?string $model = ProcurementPrediction::class;

    protected static ?string $navigationGroup = 'Shopify Sync';

    protected static ?string $navigationLabel = 'Procurement Predictions';

    protected static ?string $navigationIcon = 'heroicon-o-shopping-cart';

    protected static ?int $navigationSort = 6;

    public static function form(Form $form): Form
    {
        return $form->schema([]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->defaultSort('action_status')
            ->columns([
                TextColumn::make('sku')->searchable()->sortable(),
                TextColumn::make('product_name')->label('Product')->searchable()->wrap()->sortable(),
                TextColumn::make('variant_name')->label('Variant')->searchable()->toggleable(),
                TextColumn::make('vendor')->searchable()->sortable(),
                TextColumn::make('cms_movement_classification')->label('Movement')->badge()->sortable(),
                TextColumn::make('current_inventory')->label('Current Inventory')->numeric()->sortable(),
                TextColumn::make('total_quantity_on_order')->label('Total On Order')->numeric()->sortable(),
                TextColumn::make('projected_inventory_position')->label('Projected Inventory')->numeric()->sortable(),
                TextColumn::make('average_weekly_demand')->label('Average Weekly Demand')->numeric(2)->sortable(),
                TextColumn::make('predicted_weekly_demand')->label('Predicted Weekly Demand')->numeric(2)->sortable(),
                TextColumn::make('units_sold_per_30_in_stock_days')->label('Units / 30 In-stock Days')->numeric(2)->sortable(),
                TextColumn::make('estimated_days_of_stock_remaining')->label('Estimated Stock Days')->numeric(1)->sortable(),
                TextColumn::make('predicted_runout_date')->label('Predicted Runout')->date('d M Y')->placeholder('-')->sortable(),
                TextColumn::make('currently_on_sale')->label('On Sale')
                    ->formatStateUsing(fn (bool $state): string => $state ? 'Yes' : 'No')
                    ->badge()->color(fn (bool $state): string => $state ? 'warning' : 'gray')->sortable(),
                TextColumn::make('action_status')->label('Action Required')->badge()
                    ->formatStateUsing(fn (string $state): string => str($state)->replace('_', ' ')->title()->toString())
                    ->color(fn (string $state): string => match ($state) {
                        'ORDER_NOW' => 'danger', 'ATTENTION_WITHIN_3_WEEKS' => 'warning',
                        'MONITOR' => 'info', 'NO_ACTION' => 'success', default => 'gray',
                    })->sortable(),
                TextColumn::make('recommended_order_before_incoming_stock')
                    ->label('Recommended Before Incoming')->numeric()->sortable()->toggleable(),
                TextColumn::make('additional_order_required')->label('Additional Order Required')->numeric()->sortable(),
                TextColumn::make('preliminary_order_quantity')->label('Legacy Preliminary Qty')
                    ->numeric()->sortable()->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('data_quality_warning')->label('Data-quality Warning')->placeholder('-')->wrap()->toggleable(),
                TextColumn::make('action_reason')->label('Action Reason')->wrap(),
            ])
            ->filters([
                SelectFilter::make('procurement_prediction_run_id')->label('Prediction run')
                    ->options(fn (): array => self::runOptions())->default(fn (): ?int => self::latestRunId())->preload(),
                SelectFilter::make('action_status')->options(self::actionOptions()),
                SelectFilter::make('cms_movement_classification')->label('Movement classification')
                    ->options([
                        'FAST_MOVING' => 'Fast Moving', 'MEDIUM_MOVING' => 'Medium Moving',
                        'SLOW_MOVING' => 'Slow Moving', 'NO_SALES' => 'No Sales', 'NEW_PRODUCT' => 'New Product',
                    ]),
                SelectFilter::make('vendor')->options(fn (): array => self::distinctOptions('vendor'))->searchable()->preload(),
                SelectFilter::make('product_type')->options(fn (): array => self::distinctOptions('product_type'))->searchable()->preload(),
                TernaryFilter::make('currently_on_sale')->label('Currently on sale'),
                SelectFilter::make('data_quality_status')->options(fn (): array => self::distinctOptions('data_quality_status')),
                TernaryFilter::make('predicted_runout_within_21_days')
                    ->label('Predicted runout within 21 days')
                    ->queries(
                        true: fn (Builder $query): Builder => $query->whereBetween('predicted_runout_date', [today(), today()->addDays(21)]),
                        false: fn (Builder $query): Builder => $query->where(function (Builder $inner): void {
                            $inner->whereNull('predicted_runout_date')->orWhere('predicted_runout_date', '>', today()->addDays(21));
                        }),
                    ),
            ])
            ->headerActions([
                ExportAction::make()->label('Export Procurement Report')->icon('heroicon-o-document-arrow-down')
                    ->authorize(fn (): bool => self::canViewAny())->columnMapping(false)
                    ->formats([ExportFormat::Csv, ExportFormat::Xlsx])
                    ->exporter(ProcurementPredictionExporter::class),
            ])
            ->actions([])->bulkActions([]);
    }

    public static function canViewAny(): bool
    {
        return Auth::user()?->can(PermissionEnum::ManagerReportAccess->value) ?? false;
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
        return ['index' => Pages\ListProcurementPredictions::route('/')];
    }

    /** @return array<string,string> */
    public static function actionOptions(): array
    {
        return collect(['ORDER_NOW', 'ATTENTION_WITHIN_3_WEEKS', 'MONITOR', 'NO_ACTION', 'MANUAL_REVIEW', 'INSUFFICIENT_DATA'])
            ->mapWithKeys(fn (string $value): array => [$value => str($value)->replace('_', ' ')->title()->toString()])
            ->all();
    }

    /** @return array<int|string,string> */
    private static function runOptions(): array
    {
        return ProcurementPredictionRun::query()->where('status', ProcurementPredictionRun::STATUS_COMPLETED)
            ->latest('calculation_date')->limit(30)->get()
            ->mapWithKeys(fn (ProcurementPredictionRun $run): array => [
                $run->id => $run->calculation_date->format('d M Y')." ({$run->total_prediction_rows} predictions)",
            ])->all();
    }

    private static function latestRunId(): ?int
    {
        $id = ProcurementPredictionRun::query()->where('status', ProcurementPredictionRun::STATUS_COMPLETED)
            ->latest('calculation_date')->value('id');

        return $id === null ? null : (int) $id;
    }

    /** @return array<string,string> */
    private static function distinctOptions(string $column): array
    {
        return ProcurementPrediction::query()->whereNotNull($column)->where($column, '!=', '')
            ->distinct()->orderBy($column)->pluck($column, $column)->all();
    }
}
