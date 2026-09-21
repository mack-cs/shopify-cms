<?php

namespace App\Filament\Resources\ShopifyImageImportBatchResource\RelationManagers;

use App\Models\ShopifyImageImportItem;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;

class ItemsRelationManager extends RelationManager
{
    protected static string $relationship = 'items';

    public function table(Table $table): Table
    {
        return $table
            ->recordTitleAttribute('sku')
            ->defaultSort('id')
            ->columns([
                TextColumn::make('sku')->searchable()->sortable(),
                TextColumn::make('status')
                    ->badge()
                    ->color(fn (?string $state): string => match ($state) {
                        ShopifyImageImportItem::STATUS_UPDATED => 'success',
                        ShopifyImageImportItem::STATUS_FAILED => 'danger',
                        ShopifyImageImportItem::STATUS_SKIPPED => 'gray',
                        default => 'warning',
                    })
                    ->sortable(),
                TextColumn::make('s3_key')->label('S3 Key')->searchable()->wrap(),
                TextColumn::make('product.handle')->label('Product')->searchable()->sortable(),
                TextColumn::make('shopify_product_id')->label('Shopify Product ID')->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('message')->wrap(),
                TextColumn::make('updated_at')->dateTime()->sortable(),
            ])
            ->filters([
                SelectFilter::make('status')
                    ->options([
                        ShopifyImageImportItem::STATUS_PENDING => 'Pending',
                        ShopifyImageImportItem::STATUS_UPDATED => 'Updated',
                        ShopifyImageImportItem::STATUS_FAILED => 'Failed',
                        ShopifyImageImportItem::STATUS_SKIPPED => 'Skipped',
                    ]),
            ])
            ->headerActions([])
            ->actions([])
            ->bulkActions([]);
    }
}
