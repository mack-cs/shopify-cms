<?php

namespace App\Filament\Resources;

use App\Filament\Resources\ProductInventorySnapshotResource\Pages;
use App\Models\ProductInventorySnapshot;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Auth;

class ProductInventorySnapshotResource extends Resource
{
    protected static ?string $model = ProductInventorySnapshot::class;
    protected static ?string $navigationGroup = 'Audit & History';
    protected static ?string $navigationLabel = 'Inventory Snapshots';
    protected static ?string $navigationIcon = 'heroicon-o-chart-bar-square';
    protected static ?int $navigationSort = 13;

    public static function form(Form $form): Form
    {
        return $form->schema([]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->defaultSort('checked_at', 'desc')
            ->columns([
                TextColumn::make('checked_at')
                    ->label('Checked')
                    ->dateTime()
                    ->sortable(),
                TextColumn::make('product_title')
                    ->label('Product')
                    ->searchable()
                    ->wrap()
                    ->placeholder('Untitled product')
                    ->url(fn (ProductInventorySnapshot $record): ?string => $record->product
                        ? ProductResource::getUrl('edit', ['record' => $record->product])
                        : null),
                TextColumn::make('product_handle')
                    ->label('Handle')
                    ->searchable()
                    ->toggleable(),
                TextColumn::make('source')
                    ->badge()
                    ->formatStateUsing(fn (?string $state): string => self::sourceOptions()[$state] ?? (string) $state)
                    ->color(fn (?string $state): string => match ($state) {
                        ProductInventorySnapshot::SOURCE_LOCAL_UPDATE,
                        ProductInventorySnapshot::SOURCE_STOCK_IMPORT,
                        ProductInventorySnapshot::SOURCE_BUNDLE_COMPONENT_RULE => 'warning',
                        ProductInventorySnapshot::SOURCE_SHOPIFY_REFRESH => 'info',
                        default => 'gray',
                    })
                    ->sortable(),
                TextColumn::make('product_status')
                    ->label('Status')
                    ->badge()
                    ->color(fn (?string $state): string => match (strtolower(trim((string) $state))) {
                        'active' => 'success',
                        'draft' => 'warning',
                        'archived' => 'gray',
                        default => 'gray',
                    })
                    ->sortable(),
                IconColumn::make('is_sellable')
                    ->label('Sellable')
                    ->boolean()
                    ->trueColor('success')
                    ->falseColor('danger')
                    ->sortable(),
                IconColumn::make('is_out_of_stock')
                    ->label('Out Of Stock')
                    ->boolean()
                    ->trueColor('danger')
                    ->falseColor('gray')
                    ->sortable(),
                TextColumn::make('sellability_reason')
                    ->label('Reason')
                    ->limit(48)
                    ->tooltip(fn (ProductInventorySnapshot $record): ?string => $record->sellability_reason)
                    ->toggleable(),
                TextColumn::make('total_inventory_qty')
                    ->label('Total Qty')
                    ->placeholder('Unknown')
                    ->sortable(),
                TextColumn::make('primary_variant_qty')
                    ->label('Primary Qty')
                    ->placeholder('Unknown')
                    ->sortable()
                    ->toggleable(),
                TextColumn::make('variant_count')
                    ->label('Variants')
                    ->sortable()
                    ->toggleable(),
                TextColumn::make('sellable_variant_count')
                    ->label('Sellable Variants')
                    ->sortable()
                    ->toggleable(),
                TextColumn::make('out_of_stock_variant_count')
                    ->label('OOS Variants')
                    ->sortable()
                    ->toggleable(),
                TextColumn::make('unknown_inventory_variant_count')
                    ->label('Unknown Variants')
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                SelectFilter::make('source')
                    ->options(self::sourceOptions()),
                SelectFilter::make('product_status')
                    ->label('Product Status')
                    ->options([
                        'active' => 'Active',
                        'draft' => 'Draft',
                        'archived' => 'Archived',
                    ]),
                TernaryFilter::make('is_sellable')
                    ->label('Sellable'),
                TernaryFilter::make('is_out_of_stock')
                    ->label('Out Of Stock'),
            ]);
    }

    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()->with('product');
    }

    public static function sourceOptions(): array
    {
        return [
            ProductInventorySnapshot::SOURCE_SHOPIFY_REFRESH => 'Shopify Refresh',
            ProductInventorySnapshot::SOURCE_LOCAL_UPDATE => 'Local Update',
            ProductInventorySnapshot::SOURCE_STOCK_IMPORT => 'Stock Import',
            ProductInventorySnapshot::SOURCE_BUNDLE_COMPONENT_RULE => 'Bundle Component Rule',
        ];
    }

    public static function canViewAny(): bool
    {
        return Auth::check();
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

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListProductInventorySnapshots::route('/'),
        ];
    }
}
