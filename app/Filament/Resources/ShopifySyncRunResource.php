<?php

namespace App\Filament\Resources;

use App\Enums\RolesEnum;
use App\Filament\Resources\ShopifySyncRunResource\Pages;
use App\Filament\Resources\ShopifySyncRunResource\RelationManagers\IssuesRelationManager;
use App\Jobs\Shopify\PollShopifyInventoryBulkExport;
use App\Jobs\Shopify\PollShopifyOrdersBulkExport;
use App\Jobs\Shopify\ProcessShopifyInventoryJsonl;
use App\Jobs\Shopify\ProcessShopifyOrdersJsonl;
use App\Jobs\Shopify\RunDailyShopifyPipeline;
use App\Jobs\Shopify\RunHistoricalShopifyOrdersImport;
use App\Jobs\Shopify\RunShopifyOrdersBackfill;
use App\Jobs\Shopify\StartShopifyInventoryBulkExport;
use App\Models\ShopifySyncRun;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Auth;

class ShopifySyncRunResource extends Resource
{
    protected static ?string $model = ShopifySyncRun::class;
    protected static ?string $navigationIcon = 'heroicon-o-arrow-path-rounded-square';
    protected static ?string $navigationGroup = 'Shopify Sync';
    protected static ?string $navigationLabel = 'Sync Runs';
    protected static ?int $navigationSort = 1;

    public static function form(Form $form): Form
    {
        return $form->schema([
            Forms\Components\Section::make('Run')
                ->schema([
                    Forms\Components\TextInput::make('uuid')->disabled(),
                    Forms\Components\TextInput::make('dataset')->disabled(),
                    Forms\Components\TextInput::make('sync_type')->disabled(),
                    Forms\Components\TextInput::make('run_mode')->disabled(),
                    Forms\Components\TextInput::make('status')->disabled(),
                    Forms\Components\DatePicker::make('business_date')->disabled(),
                    Forms\Components\TextInput::make('business_timezone')->disabled(),
                    Forms\Components\DateTimePicker::make('window_start')->disabled(),
                    Forms\Components\DateTimePicker::make('window_end')->disabled(),
                    Forms\Components\TextInput::make('shopify_operation_id')->disabled()->columnSpanFull(),
                    Forms\Components\TextInput::make('shopify_operation_status')->disabled(),
                    Forms\Components\TextInput::make('raw_s3_key')->disabled()->columnSpanFull(),
                    Forms\Components\TextInput::make('metadata_s3_key')->disabled()->columnSpanFull(),
                ])
                ->columns(3),
            Forms\Components\Section::make('Counts')
                ->schema([
                    Forms\Components\TextInput::make('root_object_count')->disabled(),
                    Forms\Components\TextInput::make('object_count')->disabled(),
                    Forms\Components\TextInput::make('records_processed')->disabled(),
                    Forms\Components\TextInput::make('orders_processed')->disabled(),
                    Forms\Components\TextInput::make('order_items_processed')->disabled(),
                    Forms\Components\TextInput::make('refunds_processed')->disabled(),
                    Forms\Components\TextInput::make('discounts_processed')->disabled(),
                    Forms\Components\TextInput::make('inventory_items_processed')->disabled(),
                    Forms\Components\TextInput::make('inventory_levels_processed')->disabled(),
                    Forms\Components\TextInput::make('poll_attempts')->disabled(),
                ])
                ->columns(5),
            Forms\Components\Section::make('Errors')
                ->schema([
                    Forms\Components\Textarea::make('error_message')->disabled()->columnSpanFull(),
                    Forms\Components\Textarea::make('metadata')
                        ->disabled()
                        ->formatStateUsing(fn (mixed $state): string => is_array($state)
                            ? json_encode($state, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)
                            : (string) $state)
                        ->columnSpanFull(),
                ]),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->defaultSort('created_at', 'desc')
            ->columns([
                TextColumn::make('id')
                    ->label('Run')
                    ->formatStateUsing(fn (int $state): string => '#' . $state)
                    ->sortable(),
                TextColumn::make('dataset')->badge()->sortable(),
                TextColumn::make('sync_type')->label('Type')->badge()->sortable(),
                TextColumn::make('run_mode')->label('Mode')->badge()->sortable(),
                TextColumn::make('business_date')->date()->sortable()->placeholder('-'),
                TextColumn::make('status')
                    ->badge()
                    ->color(fn (?string $state): string => match ($state) {
                        ShopifySyncRun::STATUS_COMPLETED => 'success',
                        ShopifySyncRun::STATUS_FAILED => 'danger',
                        ShopifySyncRun::STATUS_CANCELLED => 'gray',
                        ShopifySyncRun::STATUS_PROCESSING,
                        ShopifySyncRun::STATUS_DOWNLOADING,
                        ShopifySyncRun::STATUS_RUNNING,
                        ShopifySyncRun::STATUS_STARTING,
                        ShopifySyncRun::STATUS_PENDING => 'warning',
                        default => 'gray',
                    })
                    ->sortable(),
                TextColumn::make('shopify_operation_status')->label('Shopify')->badge()->placeholder('-')->toggleable(),
                TextColumn::make('records_processed')->label('Rows')->sortable(),
                TextColumn::make('orders_processed')->label('Orders')->sortable()->toggleable(),
                TextColumn::make('order_items_processed')->label('Items')->sortable()->toggleable(),
                TextColumn::make('inventory_levels_processed')->label('Inv Levels')->sortable()->toggleable(),
                TextColumn::make('created_at')->dateTime()->sortable(),
                TextColumn::make('completed_at')->dateTime()->sortable()->placeholder('-')->toggleable(),
                TextColumn::make('error_message')->limit(40)->tooltip(fn (ShopifySyncRun $record): ?string => $record->error_message)->toggleable(),
            ])
            ->filters([
                SelectFilter::make('dataset')
                    ->options([
                        ShopifySyncRun::DATASET_ORDERS => 'Orders',
                        ShopifySyncRun::DATASET_INVENTORY => 'Inventory',
                    ]),
                SelectFilter::make('status')
                    ->options(self::statusOptions()),
                SelectFilter::make('sync_type')
                    ->options([
                        ShopifySyncRun::SYNC_TYPE_FULL => 'Full',
                        ShopifySyncRun::SYNC_TYPE_DAILY => 'Daily',
                        ShopifySyncRun::SYNC_TYPE_SNAPSHOT => 'Snapshot',
                        ShopifySyncRun::SYNC_TYPE_HISTORICAL_RANGE => 'Historical Range',
                    ]),
            ])
            ->headerActions([
                Tables\Actions\Action::make('runDailyPipeline')
                    ->label('Run Daily Pipeline')
                    ->icon('heroicon-o-clock')
                    ->form([
                        Forms\Components\DatePicker::make('business_date')
                            ->label('Business date')
                            ->helperText('Leave blank to use yesterday in Africa/Johannesburg.'),
                    ])
                    ->action(function (array $data): void {
                        RunDailyShopifyPipeline::dispatch($data['business_date'] ?? null, ShopifySyncRun::RUN_MODE_MANUAL);
                        Notification::make()->title('Shopify daily pipeline queued')->success()->send();
                    }),
                Tables\Actions\Action::make('backfillOrders')
                    ->label('Backfill Orders')
                    ->icon('heroicon-o-calendar-days')
                    ->form([
                        Forms\Components\DatePicker::make('business_date')->required(),
                        Forms\Components\TextInput::make('lookback_days')->numeric()->minValue(1)->placeholder((string) config('shopify_sync.orders.lookback_days', 3)),
                        Forms\Components\Toggle::make('capture_current_inventory')->label('Capture current inventory too'),
                    ])
                    ->action(function (array $data): void {
                        RunShopifyOrdersBackfill::dispatch(
                            (string) $data['business_date'],
                            filled($data['lookback_days'] ?? null) ? (int) $data['lookback_days'] : null,
                            (bool) ($data['capture_current_inventory'] ?? false),
                        );
                        Notification::make()->title('Shopify orders backfill queued')->success()->send();
                    }),
                Tables\Actions\Action::make('historicalOrders')
                    ->label('Historical Orders Import')
                    ->icon('heroicon-o-archive-box-arrow-down')
                    ->requiresConfirmation()
                    ->modalDescription('This starts an unfiltered Shopify orders bulk export. It is safe to rerun, but it can be large.')
                    ->action(function (): void {
                        RunHistoricalShopifyOrdersImport::dispatch();
                        Notification::make()->title('Historical orders import queued')->success()->send();
                    }),
                Tables\Actions\Action::make('inventorySnapshot')
                    ->label('Inventory Snapshot')
                    ->icon('heroicon-o-circle-stack')
                    ->action(function (): void {
                        $run = ShopifySyncRun::query()->create([
                            'dataset' => ShopifySyncRun::DATASET_INVENTORY,
                            'sync_type' => ShopifySyncRun::SYNC_TYPE_SNAPSHOT,
                            'run_mode' => ShopifySyncRun::RUN_MODE_MANUAL,
                            'business_date' => now((string) config('shopify_sync.timezone', 'Africa/Johannesburg'))->toDateString(),
                            'business_timezone' => (string) config('shopify_sync.timezone', 'Africa/Johannesburg'),
                            'status' => ShopifySyncRun::STATUS_PENDING,
                        ]);
                        StartShopifyInventoryBulkExport::dispatch($run->id);
                        Notification::make()->title("Inventory sync run #{$run->id} queued")->success()->send();
                    }),
            ])
            ->actions([
                Tables\Actions\ViewAction::make(),
                Tables\Actions\Action::make('pollNow')
                    ->label('Poll now')
                    ->icon('heroicon-o-arrow-path')
                    ->visible(fn (ShopifySyncRun $record): bool => filled($record->shopify_operation_id) && in_array($record->status, [
                        ShopifySyncRun::STATUS_RUNNING,
                        ShopifySyncRun::STATUS_STARTING,
                    ], true))
                    ->action(function (ShopifySyncRun $record): void {
                        $record->dataset === ShopifySyncRun::DATASET_INVENTORY
                            ? PollShopifyInventoryBulkExport::dispatch($record->id)
                            : PollShopifyOrdersBulkExport::dispatch($record->id);

                        Notification::make()->title("Sync run #{$record->id} poll queued")->success()->send();
                    }),
                Tables\Actions\Action::make('reprocessRawFile')
                    ->label('Reprocess raw file')
                    ->icon('heroicon-o-document-arrow-down')
                    ->requiresConfirmation()
                    ->visible(fn (ShopifySyncRun $record): bool => filled($record->raw_s3_key))
                    ->action(function (ShopifySyncRun $record): void {
                        $record->dataset === ShopifySyncRun::DATASET_INVENTORY
                            ? ProcessShopifyInventoryJsonl::dispatch($record->id)
                            : ProcessShopifyOrdersJsonl::dispatch($record->id);

                        Notification::make()->title("Sync run #{$record->id} reprocess queued")->success()->send();
                    }),
                Tables\Actions\Action::make('rerunBusinessDate')
                    ->label('Rerun date')
                    ->icon('heroicon-o-calendar')
                    ->visible(fn (ShopifySyncRun $record): bool => $record->dataset === ShopifySyncRun::DATASET_ORDERS && $record->business_date !== null)
                    ->requiresConfirmation()
                    ->action(function (ShopifySyncRun $record): void {
                        RunShopifyOrdersBackfill::dispatch($record->business_date->toDateString(), $record->lookback_days);
                        Notification::make()->title("Backfill queued for {$record->business_date->toDateString()}")->success()->send();
                    }),
            ])
            ->bulkActions([]);
    }

    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()->withCount('issues');
    }

    public static function statusOptions(): array
    {
        return [
            ShopifySyncRun::STATUS_PENDING => 'Pending',
            ShopifySyncRun::STATUS_STARTING => 'Starting',
            ShopifySyncRun::STATUS_RUNNING => 'Running',
            ShopifySyncRun::STATUS_DOWNLOADING => 'Downloading',
            ShopifySyncRun::STATUS_PROCESSING => 'Processing',
            ShopifySyncRun::STATUS_COMPLETED => 'Completed',
            ShopifySyncRun::STATUS_FAILED => 'Failed',
            ShopifySyncRun::STATUS_CANCELLED => 'Cancelled',
        ];
    }

    public static function canViewAny(): bool
    {
        return Auth::user()?->hasRole(RolesEnum::SuperAdmin->value) ?? false;
    }

    public static function canView($record): bool
    {
        return self::canViewAny();
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
            'index' => Pages\ListShopifySyncRuns::route('/'),
            'view' => Pages\ViewShopifySyncRun::route('/{record}'),
        ];
    }

    public static function getRelations(): array
    {
        return [
            IssuesRelationManager::class,
        ];
    }
}
