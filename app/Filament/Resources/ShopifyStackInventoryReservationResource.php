<?php

namespace App\Filament\Resources;

use App\Enums\RolesEnum;
use App\Filament\Resources\ShopifyStackInventoryReservationResource\Pages;
use App\Filament\Resources\ShopifyStackInventoryReservationResource\Pages\ListShopifyStackInventoryReservations;
use App\Jobs\CancelShopifyStackReservationsJob;
use App\Jobs\DeleteShopifyStackReservationsJob;
use App\Models\ShopifyStackInventoryReservation;
use App\Services\Shopify\StackReservationFulfillmentAuditService;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Form;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Tables\Actions\Action;
use Filament\Tables\Actions\BulkAction;
use Filament\Tables\Actions\BulkActionGroup;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

final class ShopifyStackInventoryReservationResource extends Resource
{
    protected static ?string $model = ShopifyStackInventoryReservation::class;

    protected static ?string $navigationGroup = 'Shopify Sync';

    protected static ?string $navigationLabel = 'Stack Reservations';

    protected static ?string $navigationIcon = 'heroicon-o-lock-closed';

    protected static ?int $navigationSort = 8;

    public static function form(Form $form): Form
    {
        return $form->schema([]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->defaultSort('created_at', 'desc')
            ->columns([
                TextColumn::make('shopify_order_name')->label('Order')
                    ->searchable(['shopify_order_name', 'shopify_order_id'])
                    ->sortable()
                    ->placeholder(fn ($record): string => $record->shopify_order_id),
                TextColumn::make('shopify_order_created_at')->label('Ordered at')->dateTime('d/m/Y H:i')->sortable()->toggleable(),
                TextColumn::make('stack_title')->label('Stack')
                    ->searchable(['stack_title', 'stack_sku'])
                    ->description(fn ($record): ?string => $record->stack_sku),
                TextColumn::make('component_title')->label('Component')
                    ->searchable(['component_title', 'component_sku'])
                    ->description(fn ($record): ?string => $record->component_sku),
                TextColumn::make('stack_quantity_ordered')->label('Stack Qty')->numeric(),
                TextColumn::make('total_component_quantity_required')->label('Required')->numeric(),
                TextColumn::make('reserved_quantity')->label('Reserved')->numeric(),
                TextColumn::make('consumed_quantity')->label('Consumed')->numeric(),
                TextColumn::make('released_quantity')->label('Released')->numeric(),
                TextColumn::make('written_off_quantity')->label('Removed')->numeric()->toggleable(),
                TextColumn::make('remaining_reserved')
                    ->label('Still reserved')
                    ->state(fn (ShopifyStackInventoryReservation $record): int => $record->remainingReserved())
                    ->numeric()
                    ->color(fn (ShopifyStackInventoryReservation $record): string => $record->remainingReserved() > 0 ? 'warning' : 'gray'),
                TextColumn::make('status')->badge()->color(fn (string $state): string => match ($state) {
                    ShopifyStackInventoryReservation::STATUS_PENDING => 'warning',
                    ShopifyStackInventoryReservation::STATUS_COMPLETED => 'success',
                    ShopifyStackInventoryReservation::STATUS_RELEASED => 'gray',
                    ShopifyStackInventoryReservation::STATUS_WRITTEN_OFF => 'danger',
                    ShopifyStackInventoryReservation::STATUS_FAILED => 'danger',
                    default => 'info',
                })
                    ->formatStateUsing(fn (string $state): string => $state === ShopifyStackInventoryReservation::STATUS_WRITTEN_OFF ? 'Deleted' : $state),
                TextColumn::make('reserved_at')->dateTime('d/m/Y H:i')->toggleable(),
                TextColumn::make('completed_at')->dateTime('d/m/Y H:i')->toggleable(),
                TextColumn::make('released_at')->dateTime('d/m/Y H:i')->toggleable(),
                TextColumn::make('error_message')->label('Error')->wrap()->limit(80)->color('danger')->toggleable(),
            ])
            ->filters([
                SelectFilter::make('status')->options([
                    ShopifyStackInventoryReservation::STATUS_PENDING_PROCESSING => 'Pending processing',
                    ShopifyStackInventoryReservation::STATUS_PENDING => 'Pending',
                    ShopifyStackInventoryReservation::STATUS_COMPLETED => 'Completed',
                    ShopifyStackInventoryReservation::STATUS_RELEASED => 'Cancelled / Released',
                    ShopifyStackInventoryReservation::STATUS_WRITTEN_OFF => 'Deleted',
                    ShopifyStackInventoryReservation::STATUS_FAILED => 'Failed',
                ]),
                SelectFilter::make('leftover')
                    ->label('Still reserved')
                    ->options(['yes' => 'Has leftover reserved stock'])
                    ->query(fn ($query, array $data) => ($data['value'] ?? null) === 'yes'
                        ? $query->withLeftoverReserved()
                        : $query),
                Filter::make('reserved_on')
                    ->label('Reserved on')
                    ->columns(2)
                    ->form([
                        DatePicker::make('from')->label('From')->native(false)->displayFormat('d/m/Y'),
                        DatePicker::make('until')->label('Until')->native(false)->displayFormat('d/m/Y'),
                    ])
                    ->query(fn (Builder $query, array $data): Builder => self::whereDayRange(
                        $query,
                        'COALESCE(reserved_at, created_at)',
                        $data['from'] ?? null,
                        $data['until'] ?? null,
                    ))
                    ->indicateUsing(fn (array $data): ?string => self::dayRangeIndicator('Reserved', $data)),
                Filter::make('ordered_on')
                    ->label('Ordered on')
                    ->columns(2)
                    ->form([
                        DatePicker::make('from')->label('From')->native(false)->displayFormat('d/m/Y'),
                        DatePicker::make('until')->label('Until')->native(false)->displayFormat('d/m/Y'),
                    ])
                    ->query(fn (Builder $query, array $data): Builder => self::whereDayRange(
                        $query,
                        'shopify_order_created_at',
                        $data['from'] ?? null,
                        $data['until'] ?? null,
                    ))
                    ->indicateUsing(fn (array $data): ?string => self::dayRangeIndicator('Ordered', $data)),
                Filter::make('order')
                    ->label('Order')
                    ->form([
                        TextInput::make('order')->label('Order id or name'),
                    ])
                    ->query(fn (Builder $query, array $data): Builder => self::whereContains(
                        $query,
                        ['shopify_order_name', 'shopify_order_id'],
                        $data['order'] ?? null,
                    ))
                    ->indicateUsing(function (array $data): ?string {
                        $term = trim((string) ($data['order'] ?? ''));

                        return $term === '' ? null : "Order {$term}";
                    }),
                Filter::make('sku')
                    ->label('SKU')
                    ->form([
                        TextInput::make('sku')->label('Stack or component SKU'),
                    ])
                    ->query(fn (Builder $query, array $data): Builder => self::whereContains(
                        $query,
                        ['stack_sku', 'component_sku'],
                        $data['sku'] ?? null,
                    ))
                    ->indicateUsing(function (array $data): ?string {
                        $term = trim((string) ($data['sku'] ?? ''));

                        return $term === '' ? null : "SKU {$term}";
                    }),
            ])
            ->headerActions([
                Action::make('scanLeftoverReservations')
                    ->label('Scan leftover reservations')
                    ->icon('heroicon-o-document-magnifying-glass')
                    ->color('warning')
                    ->visible(fn (): bool => self::canCancelReservations())
                    ->modalHeading('Scan leftover stack reservations?')
                    ->modalDescription('This asks Shopify live for fulfillment status on every reservation that still holds component stock and downloads a CSV. It does not change Shopify inventory or reservation rows. Stale rows are fixed manually.')
                    ->requiresConfirmation()
                    ->modalSubmitActionLabel('Scan and download CSV')
                    ->action(function (StackReservationFulfillmentAuditService $audit) {
                        $report = $audit->report();
                        $body = "Leftover rows {$report['leftover_rows']} across {$report['leftover_orders']} order(s). "
                            ."Fulfilled leftovers {$report['fulfilled_rows']} on {$report['fulfilled_orders']} order(s). "
                            ."Open {$report['open_rows']}. Cancelled {$report['cancelled_rows']}. Unknown {$report['unknown_rows']}.";
                        $notification = Notification::make()
                            ->title($report['fulfilled_rows'] > 0 ? 'Fulfilled leftovers found' : 'Leftover reservation scan complete')
                            ->body($body)
                            ->persistent();
                        if ($report['fulfilled_rows'] > 0) {
                            $notification->warning()->send();
                        } else {
                            $notification->success()->send();
                        }

                        $filename = 'leftover-stack-reservations-'.now()->format('Ymd-His').'.csv';

                        return response()->streamDownload(fn () => print $audit->csv($report), $filename, [
                            'Content-Type' => 'text/csv',
                        ]);
                    }),
                Action::make('deleteFilteredReservations')
                    ->label(function (ListShopifyStackInventoryReservations $livewire): string {
                        $selected = count(self::selectedIds($livewire));

                        return $selected > 0 ? "Delete {$selected} selected" : 'Delete filtered reservations';
                    })
                    ->icon('heroicon-o-trash')
                    ->color('danger')
                    ->visible(fn (): bool => self::canCancelReservations())
                    ->requiresConfirmation()
                    ->modalHeading(function (ListShopifyStackInventoryReservations $livewire): string {
                        $selected = count(self::selectedIds($livewire));

                        return $selected > 0
                            ? "Delete the {$selected} selected reservations?"
                            : 'No rows are selected. Delete every reservation in this filter?';
                    })
                    ->modalDescription(function (ListShopifyStackInventoryReservations $livewire): string {
                        $selected = count(self::selectedIds($livewire));
                        if ($selected > 0) {
                            return "This deletes only the {$selected} checked rows. The other rows in this filter stay. Held units are removed from stock and are not returned to available.";
                        }
                        $count = self::filteredLeftoverQuery($livewire)->count();

                        return "No rows are checked, so this deletes all {$count} reservations in the current filter that still hold stock. Check the rows you want if you only mean those. Held units are not returned to available.";
                    })
                    ->modalSubmitActionLabel(function (ListShopifyStackInventoryReservations $livewire): string {
                        $selected = count(self::selectedIds($livewire));

                        return $selected > 0 ? "Delete {$selected} selected" : 'Delete all in this filter';
                    })
                    ->action(function (ListShopifyStackInventoryReservations $livewire): void {
                        $selected = self::selectedIds($livewire);
                        if ($selected !== []) {
                            self::queueReservationDeletes($selected);
                            $livewire->selectedTableRecords = [];

                            return;
                        }

                        self::queueReservationDeletes(
                            self::filteredLeftoverQuery($livewire)->pluck('id')->map(fn ($id): int => (int) $id)->all()
                        );
                    }),
            ])
            ->actions([
                Action::make('cancelReservation')
                    ->label('Cancel reservation')
                    ->icon('heroicon-o-x-circle')
                    ->color('danger')
                    ->requiresConfirmation()
                    ->modalHeading('Cancel this stack reservation?')
                    ->modalDescription('This closes the reservation in the background. It does not depend on a saved fulfillment status. If live Shopify still holds the reserved units, they move back to available. If Shopify no longer holds them, the row is closed and no extra quantity is added. Already consumed units are not reversed.')
                    ->modalSubmitActionLabel('Cancel reservation')
                    ->visible(fn (): bool => self::canCancelReservations())
                    ->hidden(fn (ShopifyStackInventoryReservation $record): bool => self::isSettled($record))
                    ->action(function (ShopifyStackInventoryReservation $record): void {
                        CancelShopifyStackReservationsJob::dispatch([$record->id], Auth::id());
                        Notification::make()
                            ->title('Stack reservation cancel queued')
                            ->body('The reservation closes in the background. No extra Shopify quantity is added when the reserved units are no longer held.')
                            ->success()
                            ->send();
                    }),
                Action::make('deleteReservation')
                    ->label('Delete reservation')
                    ->icon('heroicon-o-trash')
                    ->color('danger')
                    ->requiresConfirmation()
                    ->modalHeading('Delete this stack reservation?')
                    ->modalDescription('This removes the reserved units from stock. Available does not increase. On hand drops by the units Shopify still holds as reserved. Units Shopify no longer holds are closed on this row only. Already consumed units stay consumed. The row stays as a deleted record, and a later order update does not reserve these units again.')
                    ->modalSubmitActionLabel('Delete reservation')
                    ->visible(fn (): bool => self::canCancelReservations())
                    ->hidden(fn (ShopifyStackInventoryReservation $record): bool => self::isSettled($record))
                    ->action(function (ShopifyStackInventoryReservation $record): void {
                        self::queueReservationDeletes([(int) $record->id]);
                    }),
            ])
            ->bulkActions([
                BulkActionGroup::make([
                    BulkAction::make('cancelReservations')
                        ->label('Cancel reservations')
                        ->icon('heroicon-o-x-circle')
                        ->color('danger')
                        ->requiresConfirmation()
                        ->modalHeading('Cancel the selected stack reservations?')
                        ->modalDescription('This closes the selected reservations in the background. It does not depend on a saved fulfillment status. Reserved units move back to available only when live Shopify still holds them. Already consumed units are not reversed.')
                        ->modalSubmitActionLabel('Cancel reservations')
                        ->visible(fn (): bool => self::canCancelReservations())
                        ->action(function (Collection $records): void {
                            CancelShopifyStackReservationsJob::dispatch(
                                $records->pluck('id')->map(fn ($id): int => (int) $id)->all(),
                                Auth::id(),
                            );
                            Notification::make()
                                ->title('Stack reservation cancel queued')
                                ->body('The selected reservations close in the background.')
                                ->success()
                                ->send();
                        })
                        ->deselectRecordsAfterCompletion(),
                    BulkAction::make('deleteReservations')
                        ->label('Delete reservations')
                        ->icon('heroicon-o-trash')
                        ->color('danger')
                        ->requiresConfirmation()
                        ->modalHeading('Delete the selected stack reservations?')
                        ->modalDescription('This removes the reserved units from stock. Available does not increase. On hand drops by the units Shopify still holds as reserved. Units Shopify no longer holds are closed on the row only. Already consumed units stay consumed. Deleted rows are kept, and a later order update does not reserve these units again.')
                        ->modalSubmitActionLabel('Delete reservations')
                        ->visible(fn (): bool => self::canCancelReservations())
                        ->action(function (Collection $records): void {
                            self::queueReservationDeletes(
                                $records->pluck('id')->map(fn ($id): int => (int) $id)->all()
                            );
                        })
                        ->deselectRecordsAfterCompletion(),
                ]),
            ]);
    }

    public static function getPages(): array
    {
        return ['index' => Pages\ListShopifyStackInventoryReservations::route('/')];
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

    public static function canCancelReservations(): bool
    {
        return Auth::user()?->hasRole(RolesEnum::SuperAdmin->value) ?? false;
    }

    /** @return array<int, int> */
    private static function selectedIds(ListShopifyStackInventoryReservations $livewire): array
    {
        return array_values(array_unique(array_filter(array_map(
            intval(...),
            $livewire->selectedTableRecords,
        ))));
    }

    private static function filteredLeftoverQuery(ListShopifyStackInventoryReservations $livewire): Builder
    {
        return $livewire->getFilteredTableQuery()->withLeftoverReserved();
    }

    /** @param array<int, int> $ids */
    private static function queueReservationDeletes(array $ids): void
    {
        $ids = array_values(array_unique(array_filter(array_map(intval(...), $ids))));
        if ($ids === []) {
            Notification::make()
                ->title('No reservations to delete')
                ->body('Nothing in the current filter still holds reserved stock.')
                ->warning()
                ->send();

            return;
        }

        foreach (array_chunk($ids, 25) as $chunk) {
            DeleteShopifyStackReservationsJob::dispatch($chunk, Auth::id());
        }

        Notification::make()
            ->title('Stack reservation delete queued')
            ->body(count($ids).' reservation(s) will be deleted in the background. Held units are not returned to available.')
            ->success()
            ->send();
    }

    private static function whereDayRange(Builder $query, string $expression, mixed $from, mixed $until): Builder
    {
        $from = self::calendarDay($from);
        $until = self::calendarDay($until);
        if ($from === null && $until === null) {
            return $query;
        }

        if ($from !== null) {
            $query->whereDate(DB::raw($expression), '>=', $from);
        }
        if ($until !== null) {
            $query->whereDate(DB::raw($expression), '<=', $until);
        }

        return $query;
    }

    /** @param array<string, mixed> $data */
    private static function dayRangeIndicator(string $label, array $data): ?string
    {
        $from = self::calendarDay($data['from'] ?? null);
        $until = self::calendarDay($data['until'] ?? null);
        if ($from === null && $until === null) {
            return null;
        }
        $format = fn (string $day): string => Carbon::parse($day)->format('d/m/Y');
        if ($from !== null && $until !== null) {
            return $from === $until
                ? "{$label} on ".$format($from)
                : "{$label} {$format($from)} – {$format($until)}";
        }

        return $from !== null
            ? "{$label} from ".$format($from)
            : "{$label} until ".$format((string) $until);
    }

    /** @param array<int, string> $columns */
    private static function whereContains(Builder $query, array $columns, mixed $value): Builder
    {
        $term = trim((string) $value);
        if ($term === '') {
            return $query;
        }
        $like = '%'.addcslashes($term, '%_\\').'%';

        return $query->where(function (Builder $query) use ($columns, $like): void {
            foreach ($columns as $column) {
                $query->orWhere($column, 'like', $like);
            }
        });
    }

    private static function calendarDay(mixed $value): ?string
    {
        if (! is_string($value) || trim($value) === '') {
            return null;
        }

        return Carbon::parse($value)->toDateString();
    }

    private static function isSettled(ShopifyStackInventoryReservation $record): bool
    {
        return in_array($record->status, [
            ShopifyStackInventoryReservation::STATUS_RELEASED,
            ShopifyStackInventoryReservation::STATUS_COMPLETED,
            ShopifyStackInventoryReservation::STATUS_WRITTEN_OFF,
        ], true) && $record->remainingReserved() <= 0;
    }

}
