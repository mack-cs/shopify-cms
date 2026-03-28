<?php

namespace App\Filament\Resources\ProductResource\RelationManagers;

use App\Models\Image;
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
            Tables\Columns\TextColumn::make('shopify_id')
                ->label('Shopify ID')
                ->wrap()
                ->searchable(),
            Tables\Columns\TextColumn::make('sync_state')
                ->label('Sync State')
                ->badge(),
            Tables\Columns\TextColumn::make('backup_status')
                ->label('Backup')
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
            Tables\Columns\TextColumn::make('backup_completed_at')
                ->label('Backed Up')
                ->since()
                ->sortable(),
            Tables\Columns\TextColumn::make('backup_filename')
                ->label('Backup Filename')
                ->getStateUsing(fn (Image $record): string => $record->backupFilename())
                ->wrap(),
            Tables\Columns\TextColumn::make('approved_filename')
                ->label('Approved Filename')
                ->placeholder('Not set')
                ->wrap(),
            Tables\Columns\IconColumn::make('needs_shopify_image_sync')
                ->label('Needs Shopify Sync')
                ->boolean(),
            Tables\Columns\TextColumn::make('last_shopify_image_synced_at')
                ->label('Image Synced')
                ->since()
                ->sortable(),
            Tables\Columns\TextColumn::make('alt_text')->wrap(),
        ])->filters([
            Tables\Filters\SelectFilter::make('backup_status')
                ->options([
                    Image::BACKUP_STATUS_PENDING => 'Pending',
                    Image::BACKUP_STATUS_BACKED_UP => 'Backed Up',
                    Image::BACKUP_STATUS_FAILED => 'Failed',
                    Image::BACKUP_STATUS_MISSING_SOURCE => 'Missing Source',
                ]),
        ])->headerActions([
            Tables\Actions\CreateAction::make()
                ->mutateFormDataUsing(fn (array $data): array => $this->normalizeFormData($data)),
        ])->actions([
            Tables\Actions\EditAction::make()
                ->mutateFormDataUsing(fn (array $data): array => $this->normalizeFormData($data)),
            Tables\Actions\DeleteAction::make()
                ->action(function (Image $record): void {
                    if (blank($record->shopify_id)) {
                        $record->delete();
                        return;
                    }

                    $record->update([
                        'sync_state' => Image::SYNC_STATE_LOCAL_DELETED,
                        'local_dirty' => true,
                    ]);
                }),
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
