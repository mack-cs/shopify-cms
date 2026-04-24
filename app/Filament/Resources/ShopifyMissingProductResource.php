<?php

namespace App\Filament\Resources;

use App\Filament\Resources\ShopifyMissingProductResource\Pages;
use App\Models\ShopifyMissingProduct;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Support\Facades\Auth;

class ShopifyMissingProductResource extends Resource
{
    protected static ?string $model = ShopifyMissingProduct::class;
    protected static ?string $navigationGroup = 'Audit & History';
    protected static ?string $navigationLabel = 'Shopify Missing Products';
    protected static ?string $navigationIcon = 'heroicon-o-exclamation-triangle';
    protected static ?int $navigationSort = 12;

    public static function table(Table $table): Table
    {
        return $table
            ->defaultSort('detected_at', 'desc')
            ->columns([
                TextColumn::make('detected_at')
                    ->label('Detected')
                    ->dateTime()
                    ->sortable(),
                TextColumn::make('title')
                    ->searchable()
                    ->toggleable(),
                TextColumn::make('handle')
                    ->searchable()
                    ->toggleable(),
                TextColumn::make('shopify_id')
                    ->label('Shopify ID')
                    ->searchable()
                    ->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('vendor')
                    ->toggleable(),
                TextColumn::make('status')
                    ->badge()
                    ->toggleable(),
                TextColumn::make('import_id')
                    ->label('Detected In Import')
                    ->sortable()
                    ->toggleable(),
                TextColumn::make('previous_import_id')
                    ->label('Previous Import')
                    ->sortable()
                    ->toggleable(),
            ])
            ->filters([
                SelectFilter::make('import_id')
                    ->label('Detected In Import')
                    ->options(fn () => ShopifyMissingProduct::query()
                        ->whereNotNull('import_id')
                        ->orderByDesc('import_id')
                        ->distinct()
                        ->pluck('import_id', 'import_id')
                        ->all()),
            ])
            ->actions([])
            ->bulkActions([]);
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
            'index' => Pages\ListShopifyMissingProducts::route('/'),
        ];
    }
}
