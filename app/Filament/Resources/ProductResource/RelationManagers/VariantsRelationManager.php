<?php

namespace App\Filament\Resources\ProductResource\RelationManagers;

use App\Models\Variant;
use Filament\Forms;
use Filament\Tables;
use Filament\Resources\RelationManagers\RelationManager;

class VariantsRelationManager extends RelationManager
{
    protected static string $relationship = 'variants';

    public function form(Forms\Form $form): Forms\Form
    {
        return $form->schema([
            Forms\Components\TextInput::make('sku')
                ->label('SKU')
                ->reactive()
                ->afterStateUpdated(function ($state, callable $set): void {
                    $sku = trim((string) ($state ?? ''));
                    $set('barcode', $sku === '' ? null : $sku);
                }),
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
            Tables\Columns\TextColumn::make('sku')
                ->label('SKU')
                ->searchable(),
            Tables\Columns\TextColumn::make('shopify_id')
                ->label('Shopify ID')
                ->wrap()
                ->searchable(),
            Tables\Columns\TextColumn::make('sync_state')
                ->label('Sync State')
                ->badge(),
            Tables\Columns\IconColumn::make('local_dirty')
                ->label('Local Dirty')
                ->boolean(),
            Tables\Columns\TextColumn::make('last_shopify_seen_at')
                ->label('Last Shopify Seen')
                ->since()
                ->sortable(),
            Tables\Columns\TextColumn::make('last_synced_at')
                ->label('Last Synced')
                ->since()
                ->sortable(),
            Tables\Columns\TextColumn::make('price'),
            Tables\Columns\TextColumn::make('option1_value')
                ->label('Option 1'),
        ])->headerActions([
            Tables\Actions\CreateAction::make(),
        ])->actions([
            Tables\Actions\EditAction::make(),
            Tables\Actions\DeleteAction::make()
                ->action(function (Variant $record): void {
                    if (blank($record->shopify_id)) {
                        $record->delete();
                        return;
                    }

                    $record->update([
                        'sync_state' => Variant::SYNC_STATE_LOCAL_DELETED,
                        'local_dirty' => true,
                    ]);
                }),
        ]);
    }
}
