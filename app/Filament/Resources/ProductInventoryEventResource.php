<?php

namespace App\Filament\Resources;

use App\Enums\RolesEnum;
use App\Filament\Exports\ProductExporter;
use App\Filament\Exports\ShopifyInventoryExporter;
use App\Filament\Resources\ProductInventoryEventResource\Pages;
use App\Models\ProductInventoryEvent;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables\Actions\BulkAction;
use Filament\Tables\Actions\BulkActionGroup;
use Filament\Tables\Actions\ExportBulkAction;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Auth;

class ProductInventoryEventResource extends Resource
{
    protected static ?string $model = ProductInventoryEvent::class;
    protected static ?string $navigationGroup = 'Audit & History';
    protected static ?string $navigationLabel = 'Inventory Events';
    protected static ?string $navigationIcon = 'heroicon-o-clock';
    protected static ?int $navigationSort = 14;

    public static function form(Form $form): Form
    {
        return $form->schema([]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->defaultSort('occurred_at', 'desc')
            ->columns([
                TextColumn::make('occurred_at')
                    ->label('Observed')
                    ->dateTime()
                    ->sortable(),
                TextColumn::make('event_type')
                    ->label('Event')
                    ->badge()
                    ->formatStateUsing(fn (?string $state): string => self::eventTypeOptions()[$state] ?? (string) $state)
                    ->color(fn (?string $state): string => match ($state) {
                        ProductInventoryEvent::TYPE_BECAME_UNSELLABLE,
                        ProductInventoryEvent::TYPE_FIRST_SEEN_UNSELLABLE,
                        ProductInventoryEvent::TYPE_BECAME_OUT_OF_STOCK,
                        ProductInventoryEvent::TYPE_FIRST_SEEN_OUT_OF_STOCK => 'danger',
                        ProductInventoryEvent::TYPE_BECAME_SELLABLE,
                        ProductInventoryEvent::TYPE_LEFT_OUT_OF_STOCK => 'success',
                        ProductInventoryEvent::TYPE_STATUS_CHANGED => 'warning',
                        default => 'gray',
                    })
                    ->sortable(),
                TextColumn::make('product_title')
                    ->label('Product')
                    ->searchable()
                    ->wrap()
                    ->placeholder('Untitled product')
                    ->url(fn (ProductInventoryEvent $record): ?string => $record->product
                        ? ProductResource::getUrl('edit', ['record' => $record->product])
                        : null),
                TextColumn::make('product_handle')
                    ->label('Handle')
                    ->searchable()
                    ->toggleable(),
                TextColumn::make('source')
                    ->badge()
                    ->formatStateUsing(fn (?string $state): string => ProductInventorySnapshotResource::sourceOptions()[$state] ?? (string) $state)
                    ->sortable(),
                TextColumn::make('from_status')
                    ->label('From Status')
                    ->badge()
                    ->placeholder('-')
                    ->toggleable(),
                TextColumn::make('to_status')
                    ->label('To Status')
                    ->badge()
                    ->placeholder('-')
                    ->toggleable(),
                IconColumn::make('from_is_sellable')
                    ->label('Was Sellable')
                    ->boolean()
                    ->placeholder('-')
                    ->toggleable(),
                IconColumn::make('to_is_sellable')
                    ->label('Now Sellable')
                    ->boolean()
                    ->toggleable(),
                IconColumn::make('to_is_out_of_stock')
                    ->label('Now OOS')
                    ->boolean()
                    ->trueColor('danger')
                    ->falseColor('gray')
                    ->toggleable(),
                TextColumn::make('to_reason')
                    ->label('Reason')
                    ->limit(56)
                    ->tooltip(fn (ProductInventoryEvent $record): ?string => $record->to_reason)
                    ->toggleable(),
            ])
            ->bulkActions([
                    ExportBulkAction::make()
                    ->color('danger')
                    ->extraAttributes(['class' => 'product-bulk-action product-bulk-action--export'])
                    ->exporter(ShopifyInventoryExporter::class)
                    ->visible(fn (): bool => Auth::user()?->hasAnyRole([RolesEnum::SuperAdmin->value, RolesEnum::Admin->value]) ?? false),


            ])
            ->filters([
                SelectFilter::make('event_type')
                    ->label('Event')
                    ->options(self::eventTypeOptions()),
                SelectFilter::make('source')
                    ->options(ProductInventorySnapshotResource::sourceOptions()),
            ]);
    }

    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()->with('product');
    }

    public static function eventTypeOptions(): array
    {
        return [
            ProductInventoryEvent::TYPE_FIRST_SEEN_UNSELLABLE => 'First Seen Unsellable',
            ProductInventoryEvent::TYPE_BECAME_UNSELLABLE => 'Became Unsellable',
            ProductInventoryEvent::TYPE_BECAME_SELLABLE => 'Became Sellable',
            ProductInventoryEvent::TYPE_FIRST_SEEN_OUT_OF_STOCK => 'First Seen Out Of Stock',
            ProductInventoryEvent::TYPE_BECAME_OUT_OF_STOCK => 'Became Out Of Stock',
            ProductInventoryEvent::TYPE_LEFT_OUT_OF_STOCK => 'Left Out Of Stock',
            ProductInventoryEvent::TYPE_STATUS_CHANGED => 'Status Changed',
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
            'index' => Pages\ListProductInventoryEvents::route('/'),
        ];
    }
}
