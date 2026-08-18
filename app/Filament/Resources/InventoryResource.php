<?php

namespace App\Filament\Resources;

use App\Filament\Resources\InventoryResource\Pages;
use App\Jobs\InventorySyncJob;
use App\Models\ProcurementSupplierOrderLine;
use App\Models\Product;
use App\Models\ProductInventorySnapshot;
use App\Models\Variant;
use App\Services\BulkInventoryTrackingService;
use App\Services\GoogleSheets\ProcurementSheetSyncService;
use App\Services\InventoryAccessService;
use App\Services\InventoryOperationContext;
use App\Services\Procurement\SupplierOrderService;
use App\Services\Procurement\SupplierReceiptService;
use App\Services\ProductInventoryHistoryRecorder;
use App\Services\ProductSellabilityService;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Support\Enums\MaxWidth;
use Filament\Tables\Actions\Action;
use Filament\Tables\Actions\BulkAction;
use Filament\Tables\Actions\BulkActionGroup;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Str;

class InventoryResource extends Resource
{
    protected static ?string $model = Variant::class;

    protected static ?string $navigationIcon = 'heroicon-o-archive-box';

    protected static ?string $navigationGroup = 'Catalog';

    protected static ?string $navigationLabel = 'Inventory';

    protected static ?int $navigationSort = 3;

    public static function form(Form $form): Form
    {
        return $form->schema([]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn (Builder $query): Builder => $query
                ->with(['product', 'supplierOrderLines.order', 'supplierOrderLines.receipts'])
                ->whereHas('product', fn (Builder $productQuery): Builder => $productQuery
                    ->whereRaw('LOWER(COALESCE(status, "")) != ?', ['archived'])))
            ->defaultSort('id', 'desc')
            ->columns([
                TextColumn::make('product.id')
                    ->label('Product ID')
                    ->sortable()
                    ->searchable()
                    ->visible(fn ($livewire): bool => $livewire->activeTab === 'everyday'),
                TextColumn::make('product.title')
                    ->label('Title')
                    ->searchable()
                    ->wrap(),
                TextColumn::make('product.handle')
                    ->label('Handle')
                    ->searchable()
                    ->toggleable(isToggledHiddenByDefault: true)
                    ->visible(fn ($livewire): bool => $livewire->activeTab === 'everyday'),
                TextColumn::make('product.status')
                    ->label('Status')
                    ->badge()
                    ->color(fn (?string $state): string => match (strtolower(trim((string) $state))) {
                        'active' => 'success',
                        'draft' => 'warning',
                        'archived' => 'gray',
                        default => 'gray',
                    })
                    ->visible(fn ($livewire): bool => $livewire->activeTab === 'everyday'),
                TextColumn::make('sku')
                    ->label('SKU')
                    ->searchable(),
                IconColumn::make('inventory_tracked')
                    ->label('Tracked')
                    ->icon(fn (Variant $record): string => match ($record->inventory_tracked) {
                        true => 'heroicon-m-check-circle',
                        false => 'heroicon-m-minus-circle',
                        default => 'heroicon-m-question-mark-circle',
                    })
                    ->color(fn (Variant $record): string => match ($record->inventory_tracked) {
                        true => 'success',
                        false => 'gray',
                        default => 'warning',
                    })
                    ->visible(fn ($livewire): bool => $livewire->activeTab === 'everyday'),
                TextColumn::make('inventory_qty')
                    ->label('Current Inventory')
                    ->state(fn (Variant $record): string => match ($record->inventory_tracked) {
                        false => 'Not tracked',
                        null => 'Unknown',
                        default => $record->inventory_qty !== null ? (string) ((int) $record->inventory_qty) : 'Unknown',
                    })
                    ->sortable(),
                TextColumn::make('quantity_on_order')
                    ->label('Qty On Order')
                    ->state(fn (Variant $record): int => $record->supplierOrderLines
                        ->where('status', 'open')->sum(fn ($line): int => $line->quantity_outstanding))
                    ->badge()->color('info')
                    ->visible(fn ($livewire): bool => $livewire->activeTab === 'orders'),
                TextColumn::make('next_eta')
                    ->label('Next ETA')
                    ->state(fn (Variant $record): ?string => $record->supplierOrderLines
                        ->where('status', 'open')->filter(fn ($line) => $line->quantity_outstanding > 0 && $line->eta_date)
                        ->sortBy('eta_date')->first()?->eta_date?->format('d/m/Y'))
                    ->visible(fn ($livewire): bool => $livewire->activeTab === 'orders'),
                TextColumn::make('sellable_state')
                    ->label('Sellable')
                    ->state(function (Variant $record): string {
                        $product = $record->product;
                        if (! $product instanceof Product) {
                            return 'Unknown';
                        }

                        return app(ProductSellabilityService::class)->isLocallySellable($product)
                            ? 'Sellable'
                            : 'Not Sellable';
                    })
                    ->badge()
                    ->color(fn (string $state): string => $state === 'Sellable' ? 'success' : 'danger')
                    ->visible(fn ($livewire): bool => $livewire->activeTab === 'everyday'),
                IconColumn::make('inventory_local_dirty')
                    ->label('Pending Push')
                    ->boolean()
                    ->trueColor('warning')
                    ->falseColor('success')
                    ->visible(fn ($livewire): bool => $livewire->activeTab === 'everyday'),
                TextColumn::make('inventory_sync_error')
                    ->label('Sync Error')
                    ->wrap()
                    ->toggleable(isToggledHiddenByDefault: true)
                    ->visible(fn ($livewire): bool => $livewire->activeTab === 'everyday'),
                TextColumn::make('inventory_last_synced_at')
                    ->label('From Shopify')
                    ->dateTime()
                    ->sortable()
                    ->toggleable(),
                TextColumn::make('inventory_pushed_at')
                    ->label('To Shopify')
                    ->dateTime()
                    ->sortable()
                    ->toggleable()
                    ->visible(fn ($livewire): bool => $livewire->activeTab === 'everyday'),
                TextColumn::make('last_synced_at')
                    ->label('Last Synced')
                    ->dateTime()
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true)
                    ->visible(fn ($livewire): bool => $livewire->activeTab === 'everyday'),
                TextColumn::make('updated_at')
                    ->label('Updated Date')
                    ->dateTime()
                    ->sortable()
                    ->toggleable()
                    ->visible(fn ($livewire): bool => $livewire->activeTab === 'everyday'),
                TextColumn::make('created_at')
                    ->label('Created Date')
                    ->dateTime()
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true)
                    ->visible(fn ($livewire): bool => $livewire->activeTab === 'everyday'),
            ])
            ->filters([
                SelectFilter::make('inventory_state')
                    ->label('Inventory State')
                    ->options([
                        'in_stock' => 'In Stock',
                        'out_of_stock' => 'Out Of Stock',
                        'not_tracked' => 'Not Tracked / Unknown',
                    ])
                    ->query(function (Builder $query, array $data): Builder {
                        return match ($data['value'] ?? null) {
                            'in_stock' => $query->where('inventory_tracked', true)->where('inventory_qty', '>', 0),
                            'out_of_stock' => $query->where('inventory_tracked', true)->where('inventory_qty', '<=', 0),
                            'not_tracked' => $query->where(fn (Builder $builder) => $builder
                                ->where('inventory_tracked', false)->orWhereNull('inventory_tracked')),
                            default => $query,
                        };
                    }),
                Filter::make('pending_push')
                    ->label('Pending Push')
                    ->query(fn (Builder $query): Builder => $query->where('inventory_local_dirty', true)),
                SelectFilter::make('product_status')
                    ->label('Status')
                    ->options([
                        'active' => 'Active',
                        'draft' => 'Draft',
                        'archived' => 'Archived',
                    ])
                    ->query(function (Builder $query, array $data): Builder {
                        $value = strtolower(trim((string) ($data['value'] ?? '')));
                        if ($value === '') {
                            return $query;
                        }

                        return $query->whereHas('product', fn (Builder $productQuery): Builder => $productQuery->whereRaw('LOWER(status) = ?', [$value]));
                    }),
            ])
            ->actions([
                Action::make('addSupplierOrder')
                    ->label('Add Stock On Order')->icon('heroicon-o-truck')
                    ->modalWidth(MaxWidth::Medium)
                    ->visible(fn ($livewire): bool => $livewire->activeTab === 'orders'
                        && app(InventoryAccessService::class)->canUpdateInventory(Auth::user()))
                    ->form([
                        Forms\Components\TextInput::make('order_number')->label('Order ID')->required()->maxLength(255),
                        Forms\Components\TextInput::make('quantity_ordered')->label('Quantity Ordered')->integer()->minValue(1)->required(),
                        Forms\Components\DatePicker::make('eta_date')->label('ETA')->displayFormat('d/m/Y')->native(false)->required(),
                    ])
                    ->action(function (Variant $record, array $data): void {
                        app(SupplierOrderService::class)->createForVariant($record, $data['order_number'], $data['quantity_ordered'], $data['eta_date'], Auth::id());
                        try {
                            app(ProcurementSheetSyncService::class)->publishOperational([$record->id]);
                        } catch (\Throwable $e) {
                            Notification::make()->title('Order saved; Sheet update failed')->body($e->getMessage())->warning()->send();

                            return;
                        }
                        Notification::make()->title('Supplier order added')->body('The CMS ledger and procurement Sheets were updated.')->success()->send();
                    }),
                Action::make('receiveSupplierStock')
                    ->label('Receive Stock')->icon('heroicon-o-inbox-arrow-down')->color('success')
                    ->modalWidth(MaxWidth::Medium)
                    ->visible(fn (Variant $record, $livewire): bool => $livewire->activeTab === 'orders'
                        && app(InventoryAccessService::class)->canUpdateInventory(Auth::user())
                        && $record->supplierOrderLines->where('status', 'open')->contains(fn ($line) => $line->quantity_outstanding > 0))
                    ->form([
                        Forms\Components\Select::make('line_id')->label('Supplier Order')->required()
                            ->options(fn (Variant $record): array => $record->supplierOrderLines->where('status', 'open')
                                ->filter(fn ($line) => $line->quantity_outstanding > 0)
                                ->mapWithKeys(fn ($line): array => [$line->id => (($line->order?->order_number ?: 'Legacy order').' — '.$line->quantity_outstanding.' outstanding')])->all()),
                        Forms\Components\TextInput::make('quantity_received')->label('Quantity Received')->integer()->minValue(1)->required(),
                        Forms\Components\Hidden::make('idempotency_key')->default(fn (): string => (string) Str::uuid()),
                    ])
                    ->action(function (Variant $record, array $data): void {
                        $line = ProcurementSupplierOrderLine::query()->where('variant_id', $record->id)->findOrFail($data['line_id']);
                        app(SupplierReceiptService::class)->create($line, $data['quantity_received'], $data['idempotency_key'], Auth::id());
                        Notification::make()->title('Receipt queued')->body('The Shopify delta adjustment will run on the procurement queue.')->success()->send();
                    }),
                Action::make('viewSupplierOrders')
                    ->label('Order Details')->icon('heroicon-o-eye')->color('gray')
                    ->visible(fn ($livewire): bool => $livewire->activeTab === 'orders')
                    ->modalHeading(fn (Variant $record): string => 'Supplier orders — '.($record->sku ?: "Variant {$record->id}"))
                    ->modalContent(fn (Variant $record) => view('filament.inventory.supplier-orders', ['variant' => $record->load(['supplierOrderLines.order', 'supplierOrderLines.receipts'])]))
                    ->modalSubmitAction(false)->modalCancelActionLabel('Close'),
                Action::make('editInventory')
                    ->label('Update Inventory')
                    ->icon('heroicon-o-pencil-square')
                    ->modalWidth(MaxWidth::Medium)
                    ->visible(fn ($livewire): bool => $livewire->activeTab === 'everyday'
                        && app(InventoryAccessService::class)->canUpdateInventory(Auth::user()))
                    ->form([
                        Forms\Components\Toggle::make('inventory_tracked')
                            ->label('Inventory tracked')
                            ->default(true)
                            ->live(),
                        Forms\Components\TextInput::make('inventory_qty')
                            ->label('Quantity')
                            ->numeric()
                            ->visible(fn (Forms\Get $get): bool => (bool) $get('inventory_tracked')),
                    ])
                    ->fillForm(fn (Variant $record): array => [
                        'inventory_tracked' => $record->inventory_tracked !== false,
                        'inventory_qty' => $record->inventory_qty,
                    ])
                    ->action(function (Variant $record, array $data): void {
                        InventoryOperationContext::run(function () use ($record, $data): void {
                            $record->inventory_tracked = (bool) ($data['inventory_tracked'] ?? false);
                            $record->inventory_qty = $record->inventory_tracked
                                ? (isset($data['inventory_qty']) ? (int) $data['inventory_qty'] : 0)
                                : null;
                            $record->inventory_sync_error = null;
                            $record->save();
                        });

                        $product = Product::query()->with('variants')->find($record->product_id);
                        if ($product instanceof Product) {
                            app(ProductInventoryHistoryRecorder::class)->record(
                                $product,
                                Auth::id(),
                                ProductInventorySnapshot::SOURCE_LOCAL_UPDATE,
                            );
                        }

                        Notification::make()
                            ->title('Inventory updated locally')
                            ->success()
                            ->send();
                    }),
                Action::make('updateStatus')
                    ->label('Update Status')
                    ->icon('heroicon-o-tag')
                    ->visible(fn ($livewire): bool => $livewire->activeTab === 'everyday'
                        && app(InventoryAccessService::class)->canUpdateStatus(Auth::user()))
                    ->form([
                        Forms\Components\Select::make('status')
                            ->label('Product status')
                            ->options([
                                'active' => 'Active',
                                'draft' => 'Draft',
                                'archived' => 'Archived',
                            ])
                            ->required(),
                    ])
                    ->fillForm(fn (Variant $record): array => [
                        'status' => strtolower(trim((string) ($record->product?->status ?? 'draft'))),
                    ])
                    ->action(function (Variant $record, array $data): void {
                        $product = $record->product;
                        if (! $product instanceof Product) {
                            return;
                        }

                        InventoryOperationContext::run(function () use ($product, $data): void {
                            $product->status = (string) ($data['status'] ?? 'draft');
                            $product->save();
                        });

                        $product = $product->fresh(['variants']);
                        if ($product instanceof Product) {
                            app(ProductInventoryHistoryRecorder::class)->record(
                                $product,
                                Auth::id(),
                                ProductInventorySnapshot::SOURCE_LOCAL_UPDATE,
                            );
                        }

                        Notification::make()
                            ->title('Status updated locally')
                            ->success()
                            ->send();
                    }),
                Action::make('refreshFromShopify')
                    ->label('Read From Shopify')
                    ->icon('heroicon-o-arrow-path')
                    ->visible(fn (): bool => app(InventoryAccessService::class)->canAccess(Auth::user()))
                    ->requiresConfirmation()
                    ->modalHeading('Read Inventory From Shopify')
                    ->modalDescription('This will read the latest inventory and tracking state from Shopify for the selected variant.')
                    ->modalSubmitActionLabel('Confirm Read')
                    ->action(function (Variant $record): void {
                        InventorySyncJob::dispatch([$record->id], 'refresh', Auth::id());
                        Notification::make()
                            ->title('Inventory refresh queued')
                            ->body('Shopify inventory refresh is running in the background.')
                            ->success()
                            ->send();
                    }),
                Action::make('pushToShopify')
                    ->label('Push To Shopify')
                    ->icon('heroicon-o-cloud-arrow-up')
                    ->visible(fn ($livewire): bool => $livewire->activeTab === 'everyday'
                        && app(InventoryAccessService::class)->canAccess(Auth::user()))
                    ->requiresConfirmation()
                    ->modalHeading('Push Inventory To Shopify')
                    ->modalDescription('This will push the current local inventory, tracking state, and product status to Shopify, then refresh complementary products if needed.')
                    ->modalSubmitActionLabel('Confirm Push')
                    ->action(function (Variant $record): void {
                        InventorySyncJob::dispatch(
                            [$record->id],
                            'push',
                            Auth::id(),
                            'inventory_'.now()->format('YmdHis')
                        );

                        Notification::make()
                            ->title('Inventory push queued')
                            ->body('Shopify inventory sync is running in the background.')
                            ->success()
                            ->send();
                    }),
            ])
            ->bulkActions([
                BulkActionGroup::make([
                    BulkAction::make('startTracking')
                        ->label('Start Tracking Inventory')
                        ->icon('heroicon-o-check-circle')
                        ->color('warning')
                        ->visible(fn ($livewire): bool => $livewire->activeTab === 'everyday'
                            && app(InventoryAccessService::class)->canUpdateInventory(Auth::user()))
                        ->requiresConfirmation()
                        ->modalHeading('Start tracking selected inventory?')
                        ->modalDescription('Only selected variants currently marked Not Tracked will change. Already tracked variants and variants with an unknown tracking state are skipped.')
                        ->modalSubmitActionLabel('Start Tracking')
                        ->form([
                            Forms\Components\TextInput::make('inventory_qty')
                                ->label('Starting quantity for every changed variant')
                                ->helperText('The same starting quantity is assigned to each selected Not Tracked variant.')
                                ->numeric()
                                ->integer()
                                ->minValue(0)
                                ->default(0)
                                ->required(),
                            Forms\Components\Toggle::make('push_to_shopify')
                                ->label('Push changed variants to Shopify after updating')
                                ->helperText('Leave this off to review the local changes first. You can use the existing bulk Push To Shopify action later.')
                                ->default(false),
                        ])
                        ->action(function (Collection $records, array $data): void {
                            $result = app(BulkInventoryTrackingService::class)->startTracking(
                                $records,
                                (int) ($data['inventory_qty'] ?? 0),
                                Auth::id(),
                            );

                            if (($data['push_to_shopify'] ?? false) && ! empty($result['changed_variant_ids'])) {
                                InventorySyncJob::dispatch(
                                    $result['changed_variant_ids'],
                                    'push',
                                    Auth::id(),
                                    'inventory_'.now()->format('YmdHis'),
                                );
                            }

                            $body = "Updated {$result['updated']} variant(s).";
                            if ($result['already_tracked'] > 0) {
                                $body .= " Already tracked: {$result['already_tracked']}.";
                            }
                            if ($result['unknown_skipped'] > 0) {
                                $body .= " Unknown tracking state skipped: {$result['unknown_skipped']}.";
                            }
                            if (($data['push_to_shopify'] ?? false) && $result['updated'] > 0) {
                                $body .= ' Shopify push queued.';
                            }

                            $notification = Notification::make()
                                ->title($result['updated'] > 0
                                    ? 'Bulk inventory tracking updated'
                                    : 'No untracked variants changed')
                                ->body($body);

                            if ($result['updated'] > 0) {
                                $notification->success();
                            } else {
                                $notification->warning();
                            }

                            $notification->send();
                        })
                        ->deselectRecordsAfterCompletion(),
                    BulkAction::make('refreshFromShopify')
                        ->label('Read From Shopify')
                        ->visible(fn (): bool => app(InventoryAccessService::class)->canAccess(Auth::user()))
                        ->requiresConfirmation()
                        ->modalHeading('Read Inventory From Shopify')
                        ->modalDescription('This will read the latest inventory and tracking state from Shopify for all selected variants.')
                        ->modalSubmitActionLabel('Confirm Read')
                        ->action(function (Collection $records): void {
                            InventorySyncJob::dispatch($records->pluck('id')->map(fn ($id): int => (int) $id)->all(), 'refresh', Auth::id());
                            Notification::make()
                                ->title('Inventory refresh queued')
                                ->body('Shopify inventory refresh is running in the background.')
                                ->success()
                                ->send();
                        }),
                    BulkAction::make('pushToShopify')
                        ->label('Push To Shopify')
                        ->visible(fn ($livewire): bool => $livewire->activeTab === 'everyday'
                            && app(InventoryAccessService::class)->canAccess(Auth::user()))
                        ->requiresConfirmation()
                        ->modalHeading('Push Inventory To Shopify')
                        ->modalDescription('This will push the current local inventory, tracking state, and product status to Shopify for all selected variants, then refresh complementary products if needed.')
                        ->modalSubmitActionLabel('Confirm Push')
                        ->action(function (Collection $records): void {
                            InventorySyncJob::dispatch(
                                $records->pluck('id')->map(fn ($id): int => (int) $id)->all(),
                                'push',
                                Auth::id(),
                                'inventory_'.now()->format('YmdHis')
                            );

                            Notification::make()
                                ->title('Inventory push queued')
                                ->body('Shopify inventory sync is running in the background.')
                                ->success()
                                ->send();
                        }),
                ]),
            ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListInventories::route('/'),
        ];
    }

    public static function canViewAny(): bool
    {
        return app(InventoryAccessService::class)->canAccess(Auth::user());
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

    public static function canDeleteAny(): bool
    {
        return false;
    }
}
