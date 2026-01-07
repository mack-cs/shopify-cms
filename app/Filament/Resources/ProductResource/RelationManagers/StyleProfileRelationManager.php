<?php

namespace App\Filament\Resources\ProductResource\RelationManagers;

use Filament\Forms;
use Filament\Forms\Form;
use Filament\Notifications\Notification;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables;
use Filament\Tables\Table;
use Filament\Tables\Columns\ImageColumn;
use Illuminate\Support\Carbon;

class StyleProfileRelationManager extends RelationManager
{
    protected static string $relationship = 'styleProfiles';

    protected static ?string $title = 'Styles';

    public function form(Forms\Form $form): Forms\Form
    {
        return $form->schema([
            Forms\Components\TextInput::make('image_url')->label('Image')->maxLength(2048),
            Forms\Components\TextInput::make('sku')->maxLength(80),

            Forms\Components\TextInput::make('style_type')->label('Style')->maxLength(120),
            Forms\Components\TextInput::make('materials')->maxLength(255),
            Forms\Components\TextInput::make('components')->maxLength(255),
            Forms\Components\Textarea::make('colour_prompt')->rows(2),

            Forms\Components\TextInput::make('draft_title')->label('Title')->maxLength(255),
            Forms\Components\Textarea::make('draft_description')->label('Description')->rows(5),

            Forms\Components\TextInput::make('draft_seo_title')
                ->label('SEO Title')
                ->maxLength(255),

            Forms\Components\Textarea::make('draft_seo_description')
                ->label('SEO Description (160 chars)')
                ->rows(2)
                ->maxLength(160),

            Forms\Components\Select::make('seo_sync_status')
                ->label('Sync Status')
                ->options([
                    'draft' => 'Draft',
                    'ready' => 'Ready to sync',
                ])
                ->required()
                ->default('draft'),
            Forms\Components\Textarea::make('draft_image_alt_text')
                ->label('Image Alt Text (125 chars)')
                ->rows(2)
                ->maxLength(125),
        ]);
    }

    public function table(Tables\Table $table): Tables\Table
    {
        return $table->columns([
            ImageColumn::make('image_url')
                ->label('Image')
                ->square()
                ->size(40)
                ->checkFileExistence(false)
                ->getStateUsing(function ($record): ?string {
                    $productImage = $record->product?->images()
                        ->orderBy('position')
                        ->value('src');

                    $source = $productImage ?: $record->image_url;

                    return self::normalizeImageUrl($source);
                }),
            Tables\Columns\TextColumn::make('sku')->searchable(),
            Tables\Columns\TextColumn::make('draft_title')->label('Title')->limit(60)->wrap(),
            Tables\Columns\TextColumn::make('draft_seo_title')->label('SEO Title')->limit(60)->wrap(),
            Tables\Columns\TextColumn::make('draft_seo_description')->label('SEO Desc')->limit(80)->wrap(),
            Tables\Columns\TextColumn::make('seo_sync_status')
                ->label('Sync Status')
                ->badge()
                ->color(fn (string $state): string => match ($state) {
                    'ready' => 'warning',
                    'synced' => 'success',
                    default => 'gray',
                }),
            Tables\Columns\TextColumn::make('applied_at')->dateTime()->label('Applied')->toggleable(),
        ])->headerActions([
            Tables\Actions\CreateAction::make(),
        ])->actions([
            Tables\Actions\Action::make('applySeoToProduct')
                ->label('Push SEO')
                ->requiresConfirmation()
                ->disabled(fn ($record): bool => $record->seo_sync_status !== 'ready')
                ->action(function ($record): void {
                    $product = $record->product;
                    if (!$product) {
                        Notification::make()
                            ->title('No product linked')
                            ->body('Link this style to a product before pushing SEO.')
                            ->warning()
                            ->send();
                        return;
                    }

                    $payload = [];
                    if ($record->draft_seo_title) {
                        $payload['seo_title'] = $record->draft_seo_title;
                    }
                    if ($record->draft_seo_description) {
                        $payload['seo_description'] = $record->draft_seo_description;
                    }

                    if (!$payload) {
                        Notification::make()
                            ->title('Nothing to update')
                            ->body('Add a draft SEO title or description first.')
                            ->warning()
                            ->send();
                        return;
                    }

                    $product->update($payload);
                    $record->update([
                        'seo_sync_status' => 'synced',
                        'seo_synced_at' => Carbon::now(),
                    ]);

                    Notification::make()
                        ->title('Product SEO updated')
                        ->success()
                        ->send();
                }),
            Tables\Actions\EditAction::make(),
            Tables\Actions\DeleteAction::make(),
        ]);
    }

    private static function normalizeImageUrl(?string $src): ?string
    {
        if ($src === null) {
            return null;
        }

        $trimmed = trim($src);
        if ($trimmed === '') {
            return null;
        }

        if (str_starts_with($trimmed, '//')) {
            return 'https:' . $trimmed;
        }

        return $trimmed;
    }
}
