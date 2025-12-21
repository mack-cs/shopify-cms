<?php

namespace App\Filament\Resources\ProductResource\RelationManagers;

use Filament\Forms;
use Filament\Forms\Components\Section;
use Filament\Tables;
use Filament\Tables\Columns\ImageColumn;
use Filament\Resources\RelationManagers\RelationManager;

class ImagesRelationManager extends RelationManager
{
    protected static string $relationship = 'images';

    public function form(Forms\Form $form): Forms\Form
    {
        return $form->schema([
            Section::make()->schema([
                Forms\Components\TextInput::make('src')->label('Image URL')->required(),
                Forms\Components\TextInput::make('position')->numeric(),
                Forms\Components\TextInput::make('alt_text')->label('Alt Text')->columnSpanFull(),
            ])->columns(2),
        ]);
    }

    public function table(Tables\Table $table): Tables\Table
    {
        return $table->columns([
            Tables\Columns\TextColumn::make('position'),
            ImageColumn::make('thumbnail')
                ->label('Thumbnail')
                ->square()
                ->size(50)
                ->checkFileExistence(false)
                ->getStateUsing(fn ($record) => $this->normalizeImageUrl($record->src)),
            Tables\Columns\TextColumn::make('alt_text')->wrap(),
        ])->headerActions([
            Tables\Actions\CreateAction::make(),
        ])->actions([
            Tables\Actions\EditAction::make(),
            Tables\Actions\DeleteAction::make(),
        ]);
    }

    private function normalizeImageUrl(?string $src): ?string
    {
        if ($src === null) {
            return null;
        }

        $trimmed = trim($src);
        if ($trimmed === '') {
            return null;
        }

        // Normalize protocol-relative URLs (e.g. //cdn.shopify.com/...)
        if (str_starts_with($trimmed, '//')) {
            return 'https:' . $trimmed;
        }

        return $trimmed;
    }
}
