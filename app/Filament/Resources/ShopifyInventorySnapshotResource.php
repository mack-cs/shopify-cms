<?php

namespace App\Filament\Resources;

use App\Enums\RolesEnum;
use App\Filament\Resources\ShopifyInventorySnapshotResource\Pages;
use App\Models\ShopifyInventorySnapshot;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Auth;

class ShopifyInventorySnapshotResource extends Resource
{
    protected static ?string $model = ShopifyInventorySnapshot::class;
    protected static ?string $navigationIcon = 'heroicon-o-circle-stack';
    protected static ?string $navigationGroup = 'Shopify Sync';
    protected static ?string $navigationLabel = 'Inventory';
    protected static ?int $navigationSort = 3;

    public static function form(Form $form): Form
    {
        return $form->schema([]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->defaultSort('snapshot_completed_at', 'desc')
            ->columns([
                TextColumn::make('snapshot_completed_at')->label('Captured')->dateTime()->sortable(),
                TextColumn::make('business_date')->date()->sortable(),
                TextColumn::make('sku')->searchable()->sortable(),
                TextColumn::make('product_title')->label('Product')->searchable()->wrap(),
                TextColumn::make('variant_title')->label('Variant')->searchable()->toggleable(),
                TextColumn::make('location_name')->label('Location')->searchable()->toggleable(),
                IconColumn::make('tracked')->boolean()->sortable(),
                TextColumn::make('available')->sortable(),
                TextColumn::make('on_hand')->sortable()->toggleable(),
                TextColumn::make('committed')->sortable()->toggleable(),
                TextColumn::make('incoming')->sortable()->toggleable(),
                TextColumn::make('reserved')->sortable()->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('shopify_inventory_item_id')->label('Inventory Item')->searchable()->limit(28)->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('syncRun.id')
                    ->label('Run')
                    ->formatStateUsing(fn (?int $state): string => $state ? "#{$state}" : '-')
                    ->url(fn (ShopifyInventorySnapshot $record): ?string => $record->syncRun
                        ? ShopifySyncRunResource::getUrl('view', ['record' => $record->syncRun])
                        : null),
            ])
            ->filters([
                SelectFilter::make('business_date')->options(fn (): array => ShopifyInventorySnapshot::query()
                    ->whereNotNull('business_date')
                    ->distinct()
                    ->orderByDesc('business_date')
                    ->pluck('business_date', 'business_date')
                    ->all()),
            ])
            ->actions([])
            ->bulkActions([]);
    }

    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()->with('syncRun');
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
            'index' => Pages\ListShopifyInventorySnapshots::route('/'),
        ];
    }
}
