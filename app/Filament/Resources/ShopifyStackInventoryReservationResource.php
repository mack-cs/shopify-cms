<?php

namespace App\Filament\Resources;

use App\Filament\Resources\ShopifyStackInventoryReservationResource\Pages;
use App\Models\ShopifyStackInventoryReservation;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;

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
            ]);
    }

    public static function getPages(): array
    {
        return ['index' => Pages\ListShopifyStackInventoryReservations::route('/')];
    }
}
