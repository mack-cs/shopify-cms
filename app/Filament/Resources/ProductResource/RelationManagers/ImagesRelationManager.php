<?php

namespace App\Filament\Resources\ProductResource\RelationManagers;

use Filament\Forms;
use Filament\Forms\Components\Section;
use Filament\Forms\Get;
use Filament\Tables;
use Filament\Tables\Columns\ImageColumn;
use Filament\Resources\RelationManagers\RelationManager;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class ImagesRelationManager extends RelationManager
{
    protected static string $relationship = 'images';

    public function form(Forms\Form $form): Forms\Form
    {
        return $form->schema([
            Section::make()->schema([
                Forms\Components\FileUpload::make('image_path')
                    ->label('Upload Image')
                    ->rules(['required_without:src'])
                    ->live()
                    ->visible(fn (Get $get): bool => blank($get('src')) || filled($get('image_path')))
                    ->disk('public')
                    ->directory(fn (): string => $this->uploadDirectory())
                    ->preserveFilenames()
                    ->getUploadedFileNameForStorageUsing(function ($file): string {
                        $disk = Storage::disk('public');
                        $directory = $this->uploadDirectory();
                        $original = $file->getClientOriginalName();
                        $name = pathinfo($original, PATHINFO_FILENAME);
                        $extension = strtolower((string) $file->getClientOriginalExtension());
                        $slug = Str::slug($name);
                        $slug = $slug !== '' ? $slug : 'image';
                        $suffix = '';
                        $filename = $slug;
                        $candidate = $extension !== '' ? "{$filename}.{$extension}" : $filename;
                        $path = "{$directory}/{$candidate}";

                        while ($disk->exists($path)) {
                            $suffix = $suffix === '' ? '-1' : '-' . (((int) ltrim($suffix, '-')) + 1);
                            $filename = "{$slug}{$suffix}";
                            $candidate = $extension !== '' ? "{$filename}.{$extension}" : $filename;
                            $path = "{$directory}/{$candidate}";
                        }

                        return $candidate;
                    })
                    ->image()
                    ->imageEditor()
                    ->maxSize(10240)
                    ->afterStateUpdated(function ($state, callable $set): void {
                        if (blank($state)) {
                            $set('src', null);
                        }
                    })
                    ->helperText('Upload an image to store it in the app. The stored filename keeps an SEO-friendly version of the original name.'),
                Forms\Components\TextInput::make('src')
                    ->label('Or Image URL')
                    ->placeholder('https://...')
                    ->url()
                    ->rules(['required_without:image_path'])
                    ->live()
                    ->visible(fn (Get $get): bool => blank($get('image_path')))
                    ->helperText('Use a direct public image URL when you are not uploading a file.'),
                Forms\Components\TextInput::make('position')
                    ->numeric()
                    ->default(fn (): int => $this->nextImagePosition()),
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
            Tables\Columns\TextColumn::make('source_name')
                ->label('Filename')
                ->getStateUsing(function ($record): ?string {
                    $path = is_string($record->image_path ?? null) ? trim($record->image_path) : '';
                    if ($path !== '') {
                        return basename($path);
                    }

                    $src = is_string($record->src ?? null) ? trim($record->src) : '';
                    if ($src === '') {
                        return null;
                    }

                    $parsed = parse_url($src, PHP_URL_PATH);
                    return is_string($parsed) && $parsed !== '' ? basename($parsed) : $src;
                }),
            Tables\Columns\TextColumn::make('alt_text')->wrap(),
        ])->headerActions([
            Tables\Actions\CreateAction::make()
                ->mutateFormDataUsing(fn (array $data): array => $this->normalizeFormData($data)),
        ])->actions([
            Tables\Actions\EditAction::make()
                ->mutateFormDataUsing(fn (array $data): array => $this->normalizeFormData($data)),
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

    private function normalizeFormData(array $data): array
    {
        $imagePath = is_string($data['image_path'] ?? null) ? trim($data['image_path']) : '';
        $src = is_string($data['src'] ?? null) ? trim($data['src']) : '';

        if ($imagePath !== '') {
            $data['src'] = Storage::disk('public')->url($imagePath);
            $data['image_path'] = $imagePath;
            return $data;
        }

        $data['src'] = $src !== '' ? $src : null;
        $data['image_path'] = null;

        return $data;
    }

    private function uploadDirectory(): string
    {
        $record = $this->getOwnerRecord();
        $handle = is_string($record?->handle ?? null) ? trim($record->handle) : '';
        $slug = Str::slug($handle);

        return $slug !== '' ? "product-images/{$slug}" : 'product-images';
    }

    private function nextImagePosition(): int
    {
        $maxPosition = (int) ($this->getOwnerRecord()?->images()->max('position') ?? 0);

        return $maxPosition + 1;
    }
}
