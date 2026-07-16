<?php

namespace App\Filament\Resources;

use App\Enums\RolesEnum;
use App\Filament\Resources\ShopifyOrderResource\Pages;
use App\Models\ShopifyOrder;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Auth;

class ShopifyOrderResource extends Resource
{
    protected static ?string $model = ShopifyOrder::class;
    protected static ?string $navigationIcon = 'heroicon-o-shopping-bag';
    protected static ?string $navigationGroup = 'Shopify Sync';
    protected static ?string $navigationLabel = 'Order Data';
    protected static ?int $navigationSort = 2;

    public static function form(Form $form): Form
    {
        return $form->schema([
            Forms\Components\Section::make('Order')
                ->schema([
                    Forms\Components\TextInput::make('shopify_order_id')->disabled()->columnSpanFull(),
                    Forms\Components\TextInput::make('name')->disabled(),
                    Forms\Components\TextInput::make('financial_status')->disabled(),
                    Forms\Components\TextInput::make('fulfillment_status')->disabled(),
                    Forms\Components\TextInput::make('total_amount')->disabled(),
                    Forms\Components\TextInput::make('refunded_amount')->disabled(),
                    Forms\Components\DateTimePicker::make('created_at_shopify')->disabled(),
                    Forms\Components\DateTimePicker::make('updated_at_shopify')->disabled(),
                    Forms\Components\DateTimePicker::make('processed_at_shopify')->disabled(),
                    Forms\Components\DateTimePicker::make('cancelled_at_shopify')->disabled(),
                ])
                ->columns(3),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->defaultSort('updated_at_shopify', 'desc')
            ->columns([
                TextColumn::make('name')->searchable()->sortable(),
                TextColumn::make('shopify_order_id')->label('Shopify ID')->searchable()->limit(32)->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('created_at_shopify')->label('Created')->dateTime()->sortable(),
                TextColumn::make('updated_at_shopify')->label('Updated')->dateTime()->sortable(),
                TextColumn::make('financial_status')->badge()->sortable(),
                TextColumn::make('fulfillment_status')->badge()->sortable(),
                TextColumn::make('currency_code')->toggleable(),
                TextColumn::make('total_amount')->money(fn (ShopifyOrder $record): string => $record->currency_code ?: 'ZAR')->sortable(),
                TextColumn::make('refunded_amount')->money(fn (ShopifyOrder $record): string => $record->currency_code ?: 'ZAR')->sortable()->toggleable(),
                TextColumn::make('items_count')->label('Items')->sortable(),
                IconColumn::make('is_test')->boolean()->label('Test')->sortable(),
                TextColumn::make('shipping_country')->toggleable(),
                TextColumn::make('shipping_province')->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('latestSyncRun.id')
                    ->label('Run')
                    ->formatStateUsing(fn (?int $state): string => $state ? "#{$state}" : '-')
                    ->url(fn (ShopifyOrder $record): ?string => $record->latestSyncRun
                        ? ShopifySyncRunResource::getUrl('view', ['record' => $record->latestSyncRun])
                        : null)
                    ->toggleable(),
            ])
            ->filters([
                SelectFilter::make('financial_status')->options(fn (): array => self::distinctOptions('financial_status')),
                SelectFilter::make('fulfillment_status')->options(fn (): array => self::distinctOptions('fulfillment_status')),
            ])
            ->actions([
                Tables\Actions\ViewAction::make(),
            ])
            ->bulkActions([]);
    }

    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()->with(['latestSyncRun'])->withCount('items');
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
            'index' => Pages\ListShopifyOrders::route('/'),
            'view' => Pages\ViewShopifyOrder::route('/{record}'),
        ];
    }

    private static function distinctOptions(string $column): array
    {
        return ShopifyOrder::query()
            ->whereNotNull($column)
            ->distinct()
            ->orderBy($column)
            ->pluck($column, $column)
            ->all();
    }
}
