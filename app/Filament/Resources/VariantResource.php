<?php

namespace App\Filament\Resources;

use App\Filament\Resources\VariantResource\Pages;
use App\Filament\Resources\VariantResource\RelationManagers;
use App\Models\Variant;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\SoftDeletingScope;

class VariantResource extends Resource
{
    protected static ?string $model = Variant::class;

    protected static ?string $navigationIcon = 'heroicon-o-rectangle-stack';

    public static function form(Form $form): Form
    {
        return $form
            ->schema([
                Forms\Components\TextInput::make('product_id')
                    ->required()
                    ->numeric(),
                Forms\Components\TextInput::make('sku')
                    ->label('SKU')
                    ->maxLength(255),
                Forms\Components\TextInput::make('barcode')
                    ->maxLength(255),
                Forms\Components\TextInput::make('option1_name')
                    ->maxLength(255),
                Forms\Components\TextInput::make('option1_value')
                    ->maxLength(255),
                Forms\Components\TextInput::make('option2_name')
                    ->maxLength(255),
                Forms\Components\TextInput::make('option2_value')
                    ->maxLength(255),
                Forms\Components\TextInput::make('option3_name')
                    ->maxLength(255),
                Forms\Components\TextInput::make('option3_value')
                    ->maxLength(255),
                Forms\Components\TextInput::make('price')
                    ->numeric()
                    ->prefix('R'),
                Forms\Components\TextInput::make('compare_at_price')
                    ->numeric(),
                Forms\Components\TextInput::make('inventory_qty')
                    ->numeric(),
                Forms\Components\TextInput::make('inventory_policy')
                    ->maxLength(255),
                Forms\Components\Toggle::make('requires_shipping'),
                Forms\Components\Toggle::make('taxable'),
                Forms\Components\TextInput::make('weight')
                    ->numeric(),
                Forms\Components\TextInput::make('weight_unit')
                    ->maxLength(255),
                Forms\Components\TextInput::make('position')
                    ->numeric(),
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('product_id')
                    ->numeric()
                    ->sortable(),
                Tables\Columns\TextColumn::make('sku')
                    ->label('SKU')
                    ->searchable(),
                Tables\Columns\TextColumn::make('barcode')
                    ->searchable(),
                Tables\Columns\TextColumn::make('option1_name')
                    ->searchable(),
                Tables\Columns\TextColumn::make('option1_value')
                    ->searchable(),
                Tables\Columns\TextColumn::make('option2_name')
                    ->searchable(),
                Tables\Columns\TextColumn::make('option2_value')
                    ->searchable(),
                Tables\Columns\TextColumn::make('option3_name')
                    ->searchable(),
                Tables\Columns\TextColumn::make('option3_value')
                    ->searchable(),
                Tables\Columns\TextColumn::make('price')
                    ->money()
                    ->sortable(),
                Tables\Columns\TextColumn::make('compare_at_price')
                    ->numeric()
                    ->sortable(),
                Tables\Columns\TextColumn::make('inventory_qty')
                    ->numeric()
                    ->sortable(),
                Tables\Columns\TextColumn::make('inventory_policy')
                    ->searchable(),
                Tables\Columns\IconColumn::make('requires_shipping')
                    ->boolean(),
                Tables\Columns\IconColumn::make('taxable')
                    ->boolean(),
                Tables\Columns\TextColumn::make('weight')
                    ->numeric()
                    ->sortable(),
                Tables\Columns\TextColumn::make('weight_unit')
                    ->searchable(),
                Tables\Columns\TextColumn::make('position')
                    ->numeric()
                    ->sortable(),
                Tables\Columns\TextColumn::make('created_at')
                    ->dateTime()
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
                Tables\Columns\TextColumn::make('updated_at')
                    ->dateTime()
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                Tables\Filters\TrashedFilter::make(),
            ])
            ->actions([
                Tables\Actions\EditAction::make(),
            ])
            ->bulkActions([
                Tables\Actions\BulkActionGroup::make([
                    Tables\Actions\DeleteBulkAction::make(),
                    Tables\Actions\ForceDeleteBulkAction::make(),
                    Tables\Actions\RestoreBulkAction::make(),
                ]),
            ]);
    }

    public static function getRelations(): array
    {
        return [
            //
        ];
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListVariants::route('/'),
            'create' => Pages\CreateVariant::route('/create'),
            'edit' => Pages\EditVariant::route('/{record}/edit'),
        ];
    }

    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()
            ->withoutGlobalScopes([
                SoftDeletingScope::class,
            ]);
    }
}
