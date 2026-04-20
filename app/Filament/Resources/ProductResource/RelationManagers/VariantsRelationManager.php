<?php

namespace App\Filament\Resources\ProductResource\RelationManagers;

use App\Models\Image;
use App\Models\Variant;
use Filament\Forms;
use Filament\Forms\Components\Section;
use Filament\Forms\Get;
use Filament\Tables;
use Filament\Tables\Columns\ImageColumn;
use Filament\Resources\RelationManagers\RelationManager;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class VariantsRelationManager extends RelationManager
{
    protected static string $relationship = 'variants';

    public function form(Forms\Form $form): Forms\Form
    {
        return $form->schema([
            Section::make()
                ->schema([
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
                ])
                ->columns(2),
            Section::make('Variant Image')
                ->schema([
                    Forms\Components\Select::make('image_id')
                        ->label('Linked Product Image')
                        ->options(fn (): array => $this->imageOptions())
                        ->searchable()
                        ->preload()
                        ->live()
                        ->visible(fn (Get $get): bool => blank($get('variant_image_path')) && blank($get('variant_image_src')))
                        ->helperText('Pick an existing product image or upload a new shared image below.'),
                    Forms\Components\TextInput::make('variant_image_alt_text')
                        ->label('New Image Alt Text')
                        ->maxLength(255)
                        ->live()
                        ->visible(fn (Get $get): bool => filled($get('variant_image_path')) || filled($get('variant_image_src')))
                        ->helperText('Used only when creating a new shared image from this variant.')
                        ->dehydrated(),
                    Forms\Components\FileUpload::make('variant_image_path')
                        ->label('Upload New Shared Image')
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
                        ->live()
                        ->visible(fn (Get $get): bool => blank($get('image_id')) && blank($get('variant_image_src')))
                        ->afterStateUpdated(function ($state, callable $set): void {
                            if (filled($state)) {
                                $set('image_id', null);
                                $set('variant_image_src', null);
                            }
                        })
                        ->helperText('Creates a shared product image and links this variant to it.'),
                    Forms\Components\TextInput::make('variant_image_src')
                        ->label('Or New Image URL')
                        ->placeholder('https://...')
                        ->url()
                        ->live()
                        ->visible(fn (Get $get): bool => blank($get('image_id')) && blank($get('variant_image_path')))
                        ->afterStateUpdated(function ($state, callable $set): void {
                            if (filled(trim((string) ($state ?? '')))) {
                                $set('image_id', null);
                                $set('variant_image_path', null);
                            }
                        })
                        ->helperText('Use a direct image URL to create a shared product image for this variant.'),
                ])
                ->columns(2)
                ->columnSpanFull(),
        ]);
    }

    public function table(Tables\Table $table): Tables\Table
    {
        return $table->columns([
            ImageColumn::make('variant_image')
                ->label('Image')
                ->square()
                ->size(50)
                ->checkFileExistence(false)
                ->getStateUsing(fn (Variant $record): ?string => $this->normalizeImageUrl($record->image?->src)),
            Tables\Columns\TextColumn::make('sku')
                ->label('SKU')
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
            Tables\Actions\CreateAction::make()
                ->using(function (array $data): Variant {
                    $imagePayload = $this->pullVariantImagePayload($data);
                    /** @var Variant $variant */
                    $variant = $this->getRelationship()->create($data);
                    $this->syncVariantImage($variant, $imagePayload);

                    return $variant;
                }),
        ])->actions([
            Tables\Actions\EditAction::make()
                ->using(function (array $data, Variant $record): Variant {
                    $imagePayload = $this->pullVariantImagePayload($data);
                    $record->update($data);
                    $this->syncVariantImage($record, $imagePayload);

                    return $record;
                }),
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
        ])->modifyQueryUsing(fn ($query) => $query->with('image'));
    }

    /**
     * @param array<string, mixed> $data
     * @return array{image_id:?int,variant_image_path:?string,variant_image_src:?string,variant_image_alt_text:?string}
     */
    private function pullVariantImagePayload(array &$data): array
    {
        $payload = [
            'image_id' => isset($data['image_id']) && filled($data['image_id']) ? (int) $data['image_id'] : null,
            'variant_image_path' => $this->trimToNull($data['variant_image_path'] ?? null),
            'variant_image_src' => $this->trimToNull($data['variant_image_src'] ?? null),
            'variant_image_alt_text' => $this->trimToNull($data['variant_image_alt_text'] ?? null),
        ];

        unset(
            $data['variant_image_path'],
            $data['variant_image_src'],
            $data['variant_image_alt_text']
        );

        return $payload;
    }

    /**
     * @param array{image_id:?int,variant_image_path:?string,variant_image_src:?string,variant_image_alt_text:?string} $payload
     */
    private function syncVariantImage(Variant $variant, array $payload): void
    {
        $image = $this->resolveVariantImage($variant, $payload);
        $imageId = $image?->id;

        if ((int) ($variant->image_id ?? 0) === (int) ($imageId ?? 0)) {
            return;
        }

        $variant->update([
            'image_id' => $imageId,
        ]);
    }

    /**
     * @param array{image_id:?int,variant_image_path:?string,variant_image_src:?string,variant_image_alt_text:?string} $payload
     */
    private function resolveVariantImage(Variant $variant, array $payload): ?Image
    {
        if ($payload['variant_image_path'] !== null || $payload['variant_image_src'] !== null) {
            $src = $payload['variant_image_path'] !== null
                ? Storage::disk('public')->url($payload['variant_image_path'])
                : $payload['variant_image_src'];

            return Image::create([
                'product_id' => $variant->product_id,
                'src' => $src,
                'image_path' => $payload['variant_image_path'],
                'alt_text' => $payload['variant_image_alt_text'] ?? $variant->sku,
                'position' => $this->nextImagePosition(),
            ]);
        }

        if ($payload['image_id'] === null) {
            return null;
        }

        return $this->getOwnerRecord()
            ->images()
            ->whereKey($payload['image_id'])
            ->first();
    }

    /**
     * @return array<int, string>
     */
    private function imageOptions(): array
    {
        return $this->getOwnerRecord()
            ->images()
            ->orderByRaw('CASE WHEN position IS NULL THEN 1 ELSE 0 END')
            ->orderBy('position')
            ->orderBy('id')
            ->get()
            ->mapWithKeys(fn (Image $image): array => [$image->id => $this->imageOptionLabel($image)])
            ->all();
    }

    private function imageOptionLabel(Image $image): string
    {
        $position = $image->position !== null ? '#' . $image->position : '#?';
        $filename = $this->trimToNull($image->approved_filename)
            ?? $this->trimToNull(basename((string) parse_url((string) $image->src, PHP_URL_PATH)))
            ?? ('Image ' . $image->id);

        return "{$position} {$filename}";
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

        if (str_starts_with($trimmed, '//')) {
            return 'https:' . $trimmed;
        }

        return $trimmed;
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

    private function trimToNull(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }

        $trimmed = trim((string) $value);

        return $trimmed === '' ? null : $trimmed;
    }
}
