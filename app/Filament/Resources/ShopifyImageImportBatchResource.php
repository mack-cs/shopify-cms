<?php

namespace App\Filament\Resources;

use App\Enums\RolesEnum;
use App\Filament\Resources\ShopifyImageImportBatchResource\Pages;
use App\Filament\Resources\ShopifyImageImportBatchResource\RelationManagers\ItemsRelationManager;
use App\Models\ShopifyImageImportBatch;
use Filament\Forms\Components\DateTimePicker;
use Filament\Forms\Components\Section;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Support\Facades\Auth;

class ShopifyImageImportBatchResource extends Resource
{
    protected static ?string $model = ShopifyImageImportBatch::class;
    protected static ?string $navigationIcon = 'heroicon-o-photo';
    protected static ?string $navigationGroup = 'Audit & History';
    protected static ?string $navigationLabel = 'Shopify Image Imports';
    protected static ?int $navigationSort = 20;

    public static function form(Form $form): Form
    {
        return $form->schema([
            Section::make('Import Batch')
                ->schema([
                    TextInput::make('s3_prefix')->disabled(),
                    TextInput::make('status')->disabled(),
                    TextInput::make('total_files')->disabled(),
                    TextInput::make('matched_count')->disabled(),
                    TextInput::make('updated_count')->disabled(),
                    TextInput::make('failed_count')->disabled(),
                    DateTimePicker::make('started_at')->disabled(),
                    DateTimePicker::make('completed_at')->disabled(),
                    Textarea::make('error_message')->disabled()->columnSpanFull(),
                ])
                ->columns(3),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->defaultSort('created_at', 'desc')
            ->columns([
                TextColumn::make('id')
                    ->label('Batch')
                    ->formatStateUsing(fn (int $state): string => '#' . $state)
                    ->sortable(),
                TextColumn::make('s3_prefix')->searchable()->sortable(),
                TextColumn::make('status')
                    ->badge()
                    ->color(fn (?string $state): string => match ($state) {
                        ShopifyImageImportBatch::STATUS_COMPLETED => 'success',
                        ShopifyImageImportBatch::STATUS_RUNNING,
                        ShopifyImageImportBatch::STATUS_PENDING => 'warning',
                        ShopifyImageImportBatch::STATUS_FAILED => 'danger',
                        default => 'gray',
                    })
                    ->sortable(),
                TextColumn::make('total_files')->sortable(),
                TextColumn::make('matched_count')->sortable(),
                TextColumn::make('updated_count')->sortable(),
                TextColumn::make('failed_count')->sortable(),
                TextColumn::make('creator.name')->label('Created By')->toggleable(),
                TextColumn::make('started_at')->dateTime()->sortable()->toggleable(),
                TextColumn::make('completed_at')->dateTime()->sortable()->toggleable(),
                TextColumn::make('created_at')->dateTime()->sortable()->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                SelectFilter::make('status')
                    ->options([
                        ShopifyImageImportBatch::STATUS_PENDING => 'Pending',
                        ShopifyImageImportBatch::STATUS_RUNNING => 'Running',
                        ShopifyImageImportBatch::STATUS_COMPLETED => 'Completed',
                        ShopifyImageImportBatch::STATUS_FAILED => 'Failed',
                    ]),
            ])
            ->actions([
                Tables\Actions\ViewAction::make(),
            ])
            ->bulkActions([]);
    }

    public static function getRelations(): array
    {
        return [
            ItemsRelationManager::class,
        ];
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListShopifyImageImportBatches::route('/'),
            'view' => Pages\ViewShopifyImageImportBatch::route('/{record}'),
        ];
    }

    public static function canViewAny(): bool
    {
        return Auth::user()?->hasRole(RolesEnum::SuperAdmin->value) ?? false;
    }

    public static function canView($record): bool
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
}
