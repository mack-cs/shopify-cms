<?php

namespace App\Filament\Resources;

use App\Enums\RolesEnum;
use App\Filament\Resources\ShopifyOrderItemResource\Pages;
use App\Models\ShopifyOrderItem;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Auth;

class ShopifyOrderItemResource extends Resource
{
    protected static ?string $model = ShopifyOrderItem::class;

    protected static ?string $navigationIcon = 'heroicon-o-queue-list';

    protected static ?string $navigationGroup = 'Shopify Sync';

    protected static ?string $navigationLabel = 'Order Lines';

    protected static ?int $navigationSort = 3;

    public static function form(Form $form): Form
    {
        return $form->schema([]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->defaultSort('order_created_at_shopify', 'desc')
            ->columns([
                TextColumn::make('order_created_at_shopify')
                    ->label('Sold at')
                    ->dateTime()
                    ->sortable(),
                TextColumn::make('order.name')
                    ->label('Order')
                    ->searchable()
                    ->url(fn (ShopifyOrderItem $record): ?string => $record->order
                        ? ShopifyOrderResource::getUrl('view', ['record' => $record->order])
                        : null),
                TextColumn::make('sku')->searchable()->sortable(),
                TextColumn::make('title')->label('Line item')->searchable()->wrap(),
                TextColumn::make('quantity')->sortable(),
                TextColumn::make('refund_line_items_sum_quantity')
                    ->label('Refunded units')
                    ->default(0)
                    ->sortable(),
                TextColumn::make('original_unit_price')
                    ->label('Unit price')
                    ->money(fn (ShopifyOrderItem $record): string => $record->currency_code ?: 'ZAR')
                    ->sortable(),
                TextColumn::make('discounted_total')
                    ->money(fn (ShopifyOrderItem $record): string => $record->currency_code ?: 'ZAR')
                    ->sortable(),
                TextColumn::make('order.financial_status')->label('Financial status')->badge(),
                TextColumn::make('shopify_line_item_id')
                    ->label('Shopify line ID')
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->actions([])
            ->bulkActions([]);
    }

    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()
            ->with('order')
            ->withSum('refundLineItems', 'quantity');
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
            'index' => Pages\ListShopifyOrderItems::route('/'),
        ];
    }
}
