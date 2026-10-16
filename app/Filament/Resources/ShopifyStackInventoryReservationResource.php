<?php

namespace App\Filament\Resources;

use App\Enums\RolesEnum;
use App\Filament\Resources\ShopifyStackInventoryReservationResource\Pages;
use App\Jobs\CancelShopifyStackReservationsJob;
use App\Models\ShopifyStackInventoryReservation;
use App\Services\Shopify\StackReservationFulfillmentAuditService;
use Filament\Forms\Form;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Tables\Actions\Action;
use Filament\Tables\Actions\BulkAction;
use Filament\Tables\Actions\BulkActionGroup;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Auth;

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
                TextColumn::make('shopify_order_name')->label('Order')->searchable()->sortable()
                    ->placeholder(fn ($record): string => $record->shopify_order_id),
                TextColumn::make('shopify_order_created_at')->label('Ordered at')->dateTime('d/m/Y H:i')->sortable()->toggleable(),
                TextColumn::make('stack_title')->label('Stack')->searchable()->description(fn ($record): ?string => $record->stack_sku),
                TextColumn::make('component_title')->label('Component')->searchable()->description(fn ($record): ?string => $record->component_sku),
                TextColumn::make('stack_quantity_ordered')->label('Stack Qty')->numeric(),
                TextColumn::make('total_component_quantity_required')->label('Required')->numeric(),
                TextColumn::make('reserved_quantity')->label('Reserved')->numeric(),
                TextColumn::make('consumed_quantity')->label('Consumed')->numeric(),
                TextColumn::make('released_quantity')->label('Released')->numeric(),
                TextColumn::make('remaining_reserved')
                    ->label('Still reserved')
                    ->state(fn (ShopifyStackInventoryReservation $record): int => $record->remainingReserved())
                    ->numeric()
                    ->color(fn (ShopifyStackInventoryReservation $record): string => $record->remainingReserved() > 0 ? 'warning' : 'gray'),
                TextColumn::make('status')->badge()->color(fn (string $state): string => match ($state) {
                    ShopifyStackInventoryReservation::STATUS_PENDING => 'warning',
                    ShopifyStackInventoryReservation::STATUS_COMPLETED => 'success',
                    ShopifyStackInventoryReservation::STATUS_RELEASED => 'gray',
                    ShopifyStackInventoryReservation::STATUS_FAILED => 'danger',
                    default => 'info',
                }),
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
                    ShopifyStackInventoryReservation::STATUS_FAILED => 'Failed',
                ]),
                SelectFilter::make('leftover')
                    ->label('Still reserved')
                    ->options(['yes' => 'Has leftover reserved stock'])
                    ->query(fn ($query, array $data) => ($data['value'] ?? null) === 'yes'
                        ? $query->withLeftoverReserved()
                        : $query),
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

    private static function isSettled(ShopifyStackInventoryReservation $record): bool
    {
        return in_array($record->status, [
            ShopifyStackInventoryReservation::STATUS_RELEASED,
            ShopifyStackInventoryReservation::STATUS_COMPLETED,
        ], true) && $record->remainingReserved() <= 0;
    }

}
