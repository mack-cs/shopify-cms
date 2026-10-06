<?php

namespace App\Filament\Resources;

use App\Enums\RolesEnum;
use App\Filament\Resources\ShopifyStackInventoryReservationResource\Pages;
use App\Models\ShopifyStackInventoryReservation;
use App\Services\Shopify\StackOrderReservationService;
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
            ])
            ->actions([
                Action::make('cancelReservation')
                    ->label('Cancel reservation')
                    ->icon('heroicon-o-x-circle')
                    ->color('danger')
                    ->requiresConfirmation()
                    ->modalHeading('Cancel this stack reservation?')
                    ->modalDescription('This only releases leftover reserved component stock when Shopify shows the order as fully fulfilled. Unfulfilled and partial orders are skipped so live stack reservations stay in place. Already consumed units are not reversed.')
                    ->modalSubmitActionLabel('Cancel reservation')
                    ->visible(fn (): bool => self::canCancelReservations())
                    ->hidden(fn (ShopifyStackInventoryReservation $record): bool => self::isSettled($record))
                    ->action(function (ShopifyStackInventoryReservation $record): void {
                        self::notifyCancelResult(app(StackOrderReservationService::class)->cancelManually([$record]));
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
                        ->modalDescription('This only releases leftover reserved component stock when Shopify shows the order as fully fulfilled. Unfulfilled and partial orders in the selection are skipped. Already consumed units are not reversed.')
                        ->modalSubmitActionLabel('Cancel reservations')
                        ->visible(fn (): bool => self::canCancelReservations())
                        ->action(function (Collection $records): void {
                            self::notifyCancelResult(app(StackOrderReservationService::class)->cancelManually($records));
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

    /** @param array{cancelled:int,skipped:int,failed:int,errors:array<int,string>} $result */
    private static function notifyCancelResult(array $result): void
    {
        $body = "Cancelled {$result['cancelled']}. Skipped {$result['skipped']}. Failed {$result['failed']}.";
        if ($result['errors'] !== []) {
            $body .= ' '.implode(' ', array_slice($result['errors'], 0, 5));
        }
        $notification = Notification::make()->title('Stack reservation cancel')->body($body);
        if ($result['failed'] > 0) {
            $notification->danger()->send();

            return;
        }
        if ($result['cancelled'] === 0) {
            $notification->warning()->send();

            return;
        }

        $notification->success()->send();
    }
}
