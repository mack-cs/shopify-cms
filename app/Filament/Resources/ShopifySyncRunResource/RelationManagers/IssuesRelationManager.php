<?php

namespace App\Filament\Resources\ShopifySyncRunResource\RelationManagers;

use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;

class IssuesRelationManager extends RelationManager
{
    protected static string $relationship = 'issues';

    public function table(Table $table): Table
    {
        return $table
            ->recordTitleAttribute('issue_type')
            ->defaultSort('id')
            ->columns([
                TextColumn::make('issue_type')->badge()->sortable(),
                TextColumn::make('dataset')->badge()->sortable(),
                TextColumn::make('sku')->searchable()->placeholder('-'),
                TextColumn::make('shopify_id')->label('Shopify ID')->searchable()->limit(32)->toggleable(),
                TextColumn::make('parent_shopify_id')->label('Parent')->searchable()->limit(32)->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('message')->wrap()->searchable(),
                TextColumn::make('created_at')->dateTime()->sortable(),
            ])
            ->filters([
                SelectFilter::make('issue_type')
                    ->options(fn (): array => $this->getOwnerRecord()
                        ->issues()
                        ->distinct()
                        ->orderBy('issue_type')
                        ->pluck('issue_type', 'issue_type')
                        ->all()),
            ])
            ->actions([])
            ->bulkActions([]);
    }
}
