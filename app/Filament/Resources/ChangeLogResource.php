<?php

namespace App\Filament\Resources;

use App\Filament\Resources\ChangeLogResource\Pages;
use App\Models\ChangeLog;
use Filament\Infolists\Infolist;
use Filament\Infolists\Components\Section;
use Filament\Infolists\Components\TextEntry;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Columns\TextColumn;

class ChangeLogResource extends Resource
{
    protected static ?string $model = ChangeLog::class;

    protected static ?string $navigationIcon = 'heroicon-o-clock';
    protected static ?string $navigationLabel = 'Change Log';
    protected static ?string $navigationGroup = 'Audit & History';
    protected static ?int $navigationSort = 10;

    public static function table(Table $table): Table
    {
        return $table
            ->defaultSort('created_at', 'desc')
            ->columns([
                TextColumn::make('created_at')
                    ->label('When')
                    ->dateTime()
                    ->sortable(),

                TextColumn::make('product.handle')
                    ->label('Product')
                    ->searchable()
                    ->sortable()
                    ->toggleable(),

                TextColumn::make('model_type')
                    ->label('Model')
                    ->formatStateUsing(fn ($state) => class_basename($state))
                    ->toggleable(),

                TextColumn::make('field')
                    ->label('Field')
                    ->searchable()
                    ->toggleable(),

                TextColumn::make('old_value')
                    ->label('Old Value')
                    ->limit(50)
                    ->wrap()
                    ->toggleable(isToggledHiddenByDefault: true),

                TextColumn::make('new_value')
                    ->label('New Value')
                    ->limit(50)
                    ->wrap()
                    ->toggleable(),

                TextColumn::make('changedBy.name')
                    ->label('Changed By')
                    ->sortable()
                    ->toggleable(),

                TextColumn::make('import_id')
                    ->label('Import')
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                SelectFilter::make('model_type')
                    ->label('Model Type')
                    ->options(fn () => ChangeLog::query()
                        ->distinct()
                        ->pluck('model_type', 'model_type')
                        ->mapWithKeys(fn ($v) => [$v => class_basename($v)])
                        ->toArray()
                    ),

                SelectFilter::make('changed_by')
                    ->label('Changed By')
                    ->relationship('changedBy', 'name'),

                SelectFilter::make('product_id')
                    ->label('Product')
                    ->relationship('product', 'handle'),

                SelectFilter::make('import_id')
                    ->label('Import ID'),
            ])
            ->actions([
                Tables\Actions\ViewAction::make(),
            ])
            ->bulkActions([]); // No bulk actions for audit logs
    }

    public static function infolist(Infolist $infolist): Infolist
    {
        return $infolist->schema([
            Section::make('Change')
                ->schema([
                    TextEntry::make('created_at')
                        ->label('When')
                        ->dateTime(),
                    TextEntry::make('changedBy.name')
                        ->label('Changed By')
                        ->placeholder('-'),
                    TextEntry::make('product.handle')
                        ->label('Product')
                        ->placeholder('-'),
                    TextEntry::make('model_type')
                        ->label('Model')
                        ->formatStateUsing(fn (?string $state) => $state ? class_basename($state) : '-'),
                    TextEntry::make('field')
                        ->label('Field')
                        ->placeholder('-'),
                    TextEntry::make('old_value')
                        ->label('Old Value')
                        ->placeholder('-')
                        ->columnSpanFull(),
                    TextEntry::make('new_value')
                        ->label('New Value')
                        ->placeholder('-')
                        ->columnSpanFull(),
                ])->columns(2),
            Section::make('Links')
                ->schema([
                    TextEntry::make('import_id')
                        ->label('Import ID')
                        ->placeholder('-'),
                    TextEntry::make('shopify_row_id')
                        ->label('Shopify Row ID')
                        ->placeholder('-'),
                ])->columns(2),
        ]);
    }

    /**
     * Make the resource READ-ONLY.
     */
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
            'index' => Pages\ListChangeLogs::route('/'),
            'view'  => Pages\ViewChangeLog::route('/{record}'),
        ];
    }
}
