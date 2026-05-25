<?php

namespace App\Filament\Resources;

use App\Filament\Resources\InventoryResource\Pages;
use App\Jobs\InventorySyncJob;
use App\Models\Product;
use App\Models\Variant;
use App\Services\InventoryAccessService;
use App\Services\InventoryOperationContext;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Actions\BulkAction;
use Filament\Tables\Actions\BulkActionGroup;
use Filament\Tables\Actions\Action;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Auth;

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
                ->with('product')
                ->whereHas('product', fn (Builder $productQuery): Builder => $productQuery
                    ->whereRaw('LOWER(COALESCE(status, "")) != ?', ['archived'])))
            ->defaultSort('id', 'desc')
            ->columns([
                TextColumn::make('product.id')
                    ->label('Product ID')
                    ->sortable()
                    ->searchable(),
                TextColumn::make('product.title')
                    ->label('Title')
                    ->searchable()
                    ->wrap(),
                TextColumn::make('product.handle')
                    ->label('Handle')
                    ->searchable()
                    ->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('product.status')
                    ->label('Status')
                    ->badge()
                    ->color(fn (?string $state): string => match (strtolower(trim((string) $state))) {
                        'active' => 'success',
                        'draft' => 'warning',
                        'archived' => 'gray',
                        default => 'gray',
                    }),
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
                    }),
                TextColumn::make('inventory_qty')
                    ->label('Inventory')
                    ->state(fn (Variant $record): string => match ($record->inventory_tracked) {
                        false => 'Not tracked',
                        null => 'Unknown',
                        default => $record->inventory_qty !== null ? (string) ((int) $record->inventory_qty) : 'Unknown',
                    })
                    ->sortable(),
                TextColumn::make('sellable_state')
                    ->label('Sellable')
                    ->state(function (Variant $record): string {
                        $product = $record->product;
                        if (!$product instanceof Product) {
                            return 'Unknown';
                        }

                        return app(\App\Services\ProductSellabilityService::class)->isLocallySellable($product)
                            ? 'Sellable'
                            : 'Not Sellable';
                    })
                    ->badge()
                    ->color(fn (string $state): string => $state === 'Sellable' ? 'success' : 'danger'),
                IconColumn::make('inventory_local_dirty')
                    ->label('Pending Push')
                    ->boolean()
                    ->trueColor('warning')
                    ->falseColor('success'),
                TextColumn::make('inventory_sync_error')
                    ->label('Sync Error')
                    ->wrap()
                    ->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('inventory_last_synced_at')
                    ->label('From Shopify')
                    ->dateTime()
                    ->sortable()
                    ->toggleable(),
                TextColumn::make('inventory_pushed_at')
                    ->label('To Shopify')
                    ->dateTime()
                    ->sortable()
                    ->toggleable(),
                TextColumn::make('last_synced_at')
                    ->label('Last Synced')
                    ->dateTime()
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('updated_at')
                    ->label('Updated Date')
                    ->dateTime()
                    ->sortable()
                    ->toggleable(),
                TextColumn::make('created_at')
                    ->label('Created Date')
                    ->dateTime()
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                Filter::make('tracked')
                    ->label('Tracked Only')
                    ->query(fn (Builder $query): Builder => $query->where('inventory_tracked', true)),
                Filter::make('not_tracked')
                    ->label('Not Tracked')
                    ->query(fn (Builder $query): Builder => $query->where('inventory_tracked', false)),
                Filter::make('unknown_tracking')
                    ->label('Unknown Tracking')
                    ->query(fn (Builder $query): Builder => $query->whereNull('inventory_tracked')),
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
                Action::make('editInventory')
                    ->label('Update Inventory')
                    ->icon('heroicon-o-pencil-square')
                    ->visible(fn (): bool => app(InventoryAccessService::class)->canUpdateInventory(Auth::user()))
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

                        Notification::make()
                            ->title('Inventory updated locally')
                            ->success()
                            ->send();
                    }),
                Action::make('updateStatus')
                    ->label('Update Status')
                    ->icon('heroicon-o-tag')
                    ->visible(fn (): bool => app(InventoryAccessService::class)->canUpdateStatus(Auth::user()))
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
                        if (!$product instanceof Product) {
                            return;
                        }

                        InventoryOperationContext::run(function () use ($product, $data): void {
                            $product->status = (string) ($data['status'] ?? 'draft');
                            $product->save();
                        });

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
                    ->visible(fn (): bool => app(InventoryAccessService::class)->canAccess(Auth::user()))
                    ->requiresConfirmation()
                    ->modalHeading('Push Inventory To Shopify')
                    ->modalDescription('This will push the current local inventory, tracking state, and product status to Shopify, then refresh complementary products if needed.')
                    ->modalSubmitActionLabel('Confirm Push')
                    ->action(function (Variant $record): void {
                        InventorySyncJob::dispatch(
                            [$record->id],
                            'push',
                            Auth::id(),
                            'inventory_' . now()->format('YmdHis')
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
                        ->visible(fn (): bool => app(InventoryAccessService::class)->canAccess(Auth::user()))
                        ->requiresConfirmation()
                        ->modalHeading('Push Inventory To Shopify')
                        ->modalDescription('This will push the current local inventory, tracking state, and product status to Shopify for all selected variants, then refresh complementary products if needed.')
                        ->modalSubmitActionLabel('Confirm Push')
                        ->action(function (Collection $records): void {
                            InventorySyncJob::dispatch(
                                $records->pluck('id')->map(fn ($id): int => (int) $id)->all(),
                                'push',
                                Auth::id(),
                                'inventory_' . now()->format('YmdHis')
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
