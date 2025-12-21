<?php

namespace App\Filament\Resources\ProductResource\RelationManagers;

use Filament\Forms;
use Filament\Tables;
use Filament\Resources\RelationManagers\RelationManager;

class VariantsRelationManager extends RelationManager
{
    protected static string $relationship = 'variants';

    public function form(Forms\Form $form): Forms\Form
    {
        return $form->schema([
            Forms\Components\TextInput::make('sku')->label('SKU'),
            Forms\Components\TextInput::make('barcode')->label('Barcode'),

            Forms\Components\TextInput::make('price')->numeric(),
            Forms\Components\TextInput::make('compare_at_price')->numeric(),

            Forms\Components\TextInput::make('option1_name')->label('Option1 Name'),
            Forms\Components\TextInput::make('option1_value')->label('Option1 Value'),

            Forms\Components\TextInput::make('option2_name')->label('Option2 Name'),
            Forms\Components\TextInput::make('option2_value')->label('Option2 Value'),

            Forms\Components\TextInput::make('option3_name')->label('Option3 Name'),
            Forms\Components\TextInput::make('option3_value')->label('Option3 Value'),

            Forms\Components\Toggle::make('requires_shipping'),
            Forms\Components\Toggle::make('taxable'),

            Forms\Components\TextInput::make('weight')->numeric(),
            Forms\Components\TextInput::make('weight_unit'),
        ]);
    }

    public function table(Tables\Table $table): Tables\Table
    {
        return $table->columns([
            Tables\Columns\TextColumn::make('sku')->searchable(),
            Tables\Columns\TextColumn::make('price'),
            Tables\Columns\TextColumn::make('option1_value'),
        ])->headerActions([
            Tables\Actions\CreateAction::make(),
        ])->actions([
            Tables\Actions\EditAction::make(),
            Tables\Actions\DeleteAction::make(),
        ]);
    }
}
