<?php

namespace App\Filament\Resources;

use App\Filament\Resources\StyleProfileResource\Pages;
use App\Enums\RolesEnum;
use App\Models\Product;
use App\Models\Setting;
use App\Models\StyleProfile;
use App\Services\StyleProfileCsvImporter;
use App\Services\TagNormalizer;
use Filament\Forms\Components\Checkbox;
use Filament\Forms\Get;
use Filament\Forms;
use Filament\Forms\Components\CheckboxList;
use Filament\Forms\Components\Section;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Textarea;
use Filament\Notifications\Notification;
use Filament\Tables\Actions\BulkAction;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Columns\ImageColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Table;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;
use Filament\Tables\Actions\ExportBulkAction;
use Filament\Tables\Actions\BulkActionGroup;
use Filament\Tables\Actions\DeleteBulkAction;
use Illuminate\Support\Facades\Storage;
use App\Filament\Exports\StyleProfileExporter;

class StyleProfileResource extends Resource
{
    protected static ?string $model = StyleProfile::class;

    protected static ?string $navigationIcon = 'heroicon-o-sparkles';
    protected static ?string $navigationGroup = 'Catalog';
    protected static ?string $navigationLabel = 'Style Profiles';
    protected static ?int $navigationSort = 4;

    public static function form(Forms\Form $form): Forms\Form
    {
        return $form->schema([
            Section::make('Link')
                ->schema([
                    Select::make('product_id')
                        ->label('Product')
                        ->relationship('product', 'handle')
                        ->searchable()
                        ->preload()
                        ->nullable()
                        ->getOptionLabelFromRecordUsing(fn (Product $record) => trim($record->handle . ' - ' . ($record->title ?? ''))),
                    TextInput::make('sku')->maxLength(80)->helperText('Optional. Defaults to handle.'),
                    TextInput::make('image_url')->label('Image')->maxLength(2048),
                ])->columns(2),
            Section::make('Inputs')
                ->schema([
                    TextInput::make('style_type')->label('Style')->maxLength(120),
                    TextInput::make('materials')->maxLength(255),
                    TextInput::make('components')->maxLength(255),
                    Textarea::make('colour_prompt')->rows(2),
                ])->columns(2),
            Section::make('Draft Outputs')
                ->schema([
                    TextInput::make('draft_title')->label('Title')->maxLength(255),
                    Textarea::make('draft_description')->label('Description')->rows(5),
                    TextInput::make('draft_seo_title')
                        ->label('SEO Title')
                        ->maxLength(255),
                    Textarea::make('draft_seo_description')
                        ->label('SEO Description (160 chars)')
                        ->rows(2)
                        ->maxLength(160),
                    Textarea::make('draft_image_alt_text')
                        ->label('Image Alt Text (125 chars)')
                        ->rows(2)
                        ->maxLength(125),
                ])->columns(2),
            Section::make('SEO Sync')
                ->schema([
                    Select::make('seo_sync_status')
                        ->label('Sync Status')
                        ->options([
                            'draft' => 'Draft',
                            'ready' => 'Ready to sync',
                        ])
                        ->required()
                        ->default('draft'),
                    TextInput::make('seo_synced_at')
                        ->label('Last Synced')
                        ->disabled()
                        ->dehydrated(false),
                ])->columns(2),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->defaultSort('created_at', 'desc')
            ->columns([
                ImageColumn::make('thumbnail')
                    ->label('Image')
                    ->square()
                    ->size(40)
                    ->checkFileExistence(false)
                    ->getStateUsing(function (StyleProfile $record): ?string {
                        $productImage = $record->product?->images()
                            ->orderBy('position')
                            ->value('src');

                        $source = $productImage ?: $record->image_url;

                        return self::normalizeImageUrl($source);
                    })
                    ->toggleable(),
                TextColumn::make('product.title')
                    ->label('Product')
                    ->searchable()
                    ->sortable()
                    ->toggleable(),
                TextColumn::make('product.vendor')
                    ->label('Vendor')
                    ->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('handle')
                    ->label('Handle')
                    ->searchable()
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('sku')
                    ->searchable()
                    ->toggleable(),
                TextColumn::make('materials')
                    ->searchable()
                    ->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('style_type')->label('Style')->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('draft_title')
                    ->label('Title')
                    ->limit(60)
                    ->wrap()
                    ->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('draft_description')
                    ->label('Description')
                    ->limit(80)
                    ->wrap()
                    ->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('draft_seo_title')->label('SEO Title')->limit(60)->wrap()->toggleable(),
                TextColumn::make('draft_seo_description')
                    ->label('SEO Desc')
                    ->limit(80)
                    ->wrap()
                    ->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('draft_image_alt_text')
                    ->label('Image Alt')
                    ->limit(80)
                    ->wrap()
                    ->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('image_url')
                    ->label('Image URL')
                    ->limit(60)
                    ->wrap()
                    ->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('seo_sync_status')
                    ->label('Sync Status')
                    ->badge()
                    ->color(fn (string $state): string => match ($state) {
                        'ready' => 'warning',
                        'synced' => 'success',
                        default => 'gray',
                    }),
                TextColumn::make('seo_synced_at')
                    ->label('Synced At')
                    ->dateTime()
                    ->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('applied_at')
                    ->dateTime()
                    ->label('Applied')
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                SelectFilter::make('vendor')
                    ->label('Vendor')
                    ->options(fn () => Product::query()
                        ->whereNotNull('vendor')
                        ->where('vendor', '!=', '')
                        ->distinct()
                        ->orderBy('vendor')
                        ->pluck('vendor', 'vendor')
                        ->all())
                    ->searchable()
                    ->query(function ($query, array $data) {
                        $vendor = $data['value'] ?? null;
                        if (!$vendor) {
                            return;
                        }

                        $query->whereHas('product', fn ($builder) => $builder->where('vendor', $vendor));
                    }),
                SelectFilter::make('product_tag')
                    ->label('Tag')
                    ->options(fn (): array => self::tagOptions())
                    ->searchable()
                    ->query(function ($query, array $data) {
                        $tag = $data['value'] ?? null;
                        if (!$tag) {
                            return;
                        }

                        $query->whereHas('product', function ($builder) use ($tag) {
                            $builder->whereRaw(
                                "CONCAT(',', REPLACE(tags, ' ', ''), ',') LIKE ?",
                                ["%,{$tag},%"]
                            );
                        });
                    }),
                SelectFilter::make('product_color')
                    ->label('Color')
                    ->options(fn (): array => self::colorOptions())
                    ->searchable()
                    ->query(function ($query, array $data) {
                        $color = $data['value'] ?? null;
                        if (!$color) {
                            return;
                        }

                        $query->whereHas('product', function ($builder) use ($color) {
                            $builder->whereRaw(
                                "CONCAT(';', REPLACE(color_string, ' ', ''), ';') LIKE ?",
                                ["%;{$color};%"]
                            );
                        });
                    }),
                TernaryFilter::make('unlinked')
                    ->label('Unlinked')
                    ->queries(
                        true: fn ($query) => $query->whereNull('product_id'),
                        false: fn ($query) => $query->whereNotNull('product_id'),
                    ),
            ])
            ->actions([
                Tables\Actions\EditAction::make()->modalHeading('Edit Style Profile'),
                Tables\Actions\DeleteAction::make(),
            ])
            ->headerActions([
                Tables\Actions\Action::make('toggleSeoLock')
                    ->label(function (): string {
                        $default = config('style_profiles.lock_product_seo', true);
                        $enabled = Setting::getBool('style_profiles.lock_product_seo', $default);
                        return $enabled ? 'SEO Lock: On' : 'SEO Lock: Off';
                    })
                    ->color(function (): string {
                        $default = config('style_profiles.lock_product_seo', true);
                        return Setting::getBool('style_profiles.lock_product_seo', $default) ? 'success' : 'gray';
                    })
                    ->icon(fn (): string => 'heroicon-o-lock-closed')
                    ->requiresConfirmation()
                    ->action(function (): void {
                        $default = config('style_profiles.lock_product_seo', true);
                        $enabled = Setting::getBool('style_profiles.lock_product_seo', $default);
                        Setting::putBool('style_profiles.lock_product_seo', !$enabled);

                        Notification::make()
                            ->title(!$enabled ? 'SEO lock enabled' : 'SEO lock disabled')
                            ->success()
                            ->send();
                    }),
                Tables\Actions\CreateAction::make()
                    ->modalHeading('Add Style Profile')
                    ->color('success')
                    ->createAnother(false),
                Tables\Actions\Action::make('importCsv')
                    ->label('Import CSV')
                    ->color('info')
                    ->form([
                        Forms\Components\FileUpload::make('file')
                            ->label('CSV File')
                            ->required()
                            ->disk('local')
                            ->directory('imports')
                            ->acceptedFileTypes(['text/csv', 'text/plain', 'application/vnd.ms-excel']),
                    ])
                    ->action(function (array $data, StyleProfileCsvImporter $importer): void {
                        $path = Storage::disk('local')->path($data['file']);
                        $result = $importer->importFromPath($path);

                        Notification::make()
                            ->title('Import complete')
                            ->body(
                                "Total: {$result['total']}, Imported: {$result['imported']}, " .
                                "No Handle: {$result['skipped_no_handle']}, " .
                                "No product link: {$result['unlinked_no_product']}"
                            )
                            ->success()
                            ->send();
                    }),
                Tables\Actions\Action::make('loadMissingProducts')
                    ->label('Load Missing Products')
                    ->color('warning')
                    ->requiresConfirmation()
                    ->form([
                        Select::make('vendor')
                            ->label('Vendor')
                            ->options(fn () => Product::query()
                                ->whereNotNull('vendor')
                                ->where('vendor', '!=', '')
                                ->distinct()
                                ->orderBy('vendor')
                                ->pluck('vendor', 'vendor')
                                ->all())
                            ->searchable()
                            ->placeholder('All vendors')
                            ->nullable(),
                        CheckboxList::make('fields')
                            ->label('Copy fields')
                            ->options([
                                'title' => 'Title',
                                'description' => 'Description',
                                'seo_title' => 'SEO title',
                                'seo_description' => 'SEO description',
                                'image_alt' => 'Image alt text',
                                'image_url' => 'Image URL',
                            ])
                            ->columns(2)
                            ->default(['title', 'description', 'seo_title', 'seo_description', 'image_alt', 'image_url']),
                    ])
                    ->action(function (array $data): void {
                        $fields = array_fill_keys($data['fields'] ?? [], true);
                        $vendor = $data['vendor'] ?? null;

                        $created = 0;
                        $skippedExisting = 0;

                        Product::query()
                            ->when($vendor, fn ($query) => $query->where('vendor', $vendor))
                            ->with(['variants', 'images'])
                            ->chunkById(200, function ($products) use (&$created, &$skippedExisting, $fields): void {
                                foreach ($products as $product) {
                                    if (!$product->handle) {
                                        continue;
                                    }

                                    $exists = StyleProfile::where('handle', $product->handle)->exists();
                                    if ($exists) {
                                        $skippedExisting++;
                                        continue;
                                    }

                                    $image = $product->images
                                        ->sortBy(fn ($img) => $img->position ?? PHP_INT_MAX)
                                        ->first();
                                    $imageUrl = $image?->src;
                                    $imageAlt = $image?->alt_text;

                                    $sku = trim((string) ($product->variants->first()?->sku ?? ''));
                                    if ($sku === '') {
                                        $sku = $product->handle;
                                    }

                                    $payload = [
                                        'product_id' => $product->id,
                                        'handle' => $product->handle,
                                        'sku' => $sku,
                                    ];

                                    if (!empty($fields['title']) && $product->title) {
                                        $payload['draft_title'] = $product->title;
                                    }
                                    if (!empty($fields['description']) && $product->body_html) {
                                        $payload['draft_description'] = $product->body_html;
                                    }
                                    if (!empty($fields['seo_title']) && $product->seo_title) {
                                        $payload['draft_seo_title'] = $product->seo_title;
                                    }
                                    if (!empty($fields['seo_description']) && $product->seo_description) {
                                        $payload['draft_seo_description'] = $product->seo_description;
                                    }
                                    if (!empty($fields['image_alt']) && $imageAlt) {
                                        $payload['draft_image_alt_text'] = $imageAlt;
                                    }
                                    if (!empty($fields['image_url']) && $imageUrl) {
                                        $payload['image_url'] = $imageUrl;
                                    }

                                    StyleProfile::create($payload);
                                    $created++;
                                }
                            });

                        Notification::make()
                            ->title('Style profiles loaded')
                            ->body("Created {$created}. Skipped (existing handle): {$skippedExisting}.")
                            ->success()
                            ->send();
                    }),
            ])
            ->bulkActions([
                BulkActionGroup::make([
                    DeleteBulkAction::make()
                        ->label('Delete Styles')
                        ->visible(fn (): bool => Auth::user()?->hasAnyRole([RolesEnum::SuperAdmin->value, RolesEnum::Admin->value]) ?? false),
                    BulkAction::make('markReady')
                        ->label('Mark Ready')
                        ->icon('heroicon-o-check-circle')
                        ->requiresConfirmation()
                        ->action(function ($records): void {
                            $updated = 0;
                            foreach ($records as $record) {
                                if ($record->seo_sync_status === 'ready') {
                                    continue;
                                }
                                $record->update(['seo_sync_status' => 'ready']);
                                $updated++;
                            }

                            Notification::make()
                                ->title('Styles marked ready')
                                ->body("Updated {$updated} style(s).")
                                ->success()
                                ->send();
                        })
                        ->deselectRecordsAfterCompletion(),
                    BulkAction::make('bulkUpdateInputs')
                        ->label('Bulk Update Inputs')
                        ->icon('heroicon-o-pencil-square')
                        ->form([
                            Forms\Components\Grid::make(2)
                                ->schema([
                                    Checkbox::make('update_style_type')
                                        ->label('Style')
                                        ->inline()
                                        ->live(),
                                    TextInput::make('style_type')
                                        ->label('Style')
                                        ->maxLength(120)
                                        ->disabled(fn (Get $get): bool => !$get('update_style_type')),
                                    Checkbox::make('update_materials')
                                        ->label('Materials')
                                        ->inline()
                                        ->live(),
                                    TextInput::make('materials')
                                        ->label('Materials')
                                        ->maxLength(255)
                                        ->disabled(fn (Get $get): bool => !$get('update_materials')),
                                    Checkbox::make('update_components')
                                        ->label('Components')
                                        ->inline()
                                        ->live(),
                                    TextInput::make('components')
                                        ->label('Components')
                                        ->maxLength(255)
                                        ->disabled(fn (Get $get): bool => !$get('update_components')),
                                    Checkbox::make('update_colour_prompt')
                                        ->label('Colour prompt')
                                        ->inline()
                                        ->live(),
                                    Textarea::make('colour_prompt')
                                        ->label('Colour prompt')
                                        ->rows(2)
                                        ->disabled(fn (Get $get): bool => !$get('update_colour_prompt')),
                                ]),
                        ])
                        ->action(function ($records, array $data): void {
                            $fields = [
                                'style_type' => (bool) ($data['update_style_type'] ?? false),
                                'materials' => (bool) ($data['update_materials'] ?? false),
                                'components' => (bool) ($data['update_components'] ?? false),
                                'colour_prompt' => (bool) ($data['update_colour_prompt'] ?? false),
                            ];

                            if (!array_filter($fields)) {
                                Notification::make()
                                    ->title('Choose at least one field')
                                    ->warning()
                                    ->send();
                                return;
                            }

                            $payload = [];
                            if (!empty($fields['style_type'])) {
                                $payload['style_type'] = self::nullIfEmpty($data['style_type'] ?? null);
                            }
                            if (!empty($fields['materials'])) {
                                $payload['materials'] = self::nullIfEmpty($data['materials'] ?? null);
                            }
                            if (!empty($fields['components'])) {
                                $payload['components'] = self::nullIfEmpty($data['components'] ?? null);
                            }
                            if (!empty($fields['colour_prompt'])) {
                                $payload['colour_prompt'] = self::nullIfEmpty($data['colour_prompt'] ?? null);
                            }

                            if (!$payload) {
                                Notification::make()
                                    ->title('No fields to update')
                                    ->warning()
                                    ->send();
                                return;
                            }

                            foreach ($records as $record) {
                                $record->update($payload);
                            }

                            Notification::make()
                                ->title('Styles updated')
                                ->success()
                                ->send();
                        }),
                    BulkAction::make('pushReadySeo')
                        ->label('Push to Product (Ready)')
                        ->icon('heroicon-o-cloud-arrow-up')
                        ->requiresConfirmation()
                        ->form([
                            CheckboxList::make('fields')
                                ->label('Fields to sync')
                                ->options([
                                    'title' => 'Title',
                                    'description' => 'Description',
                                    'seo_title' => 'SEO title',
                                    'seo_description' => 'SEO description',
                                    'image_alt' => 'Image alt text',
                                ])
                                ->columns(2)
                                ->default(['seo_title', 'seo_description']),
                        ])
                        ->action(function ($records, array $data): void {
                            $fields = array_fill_keys($data['fields'] ?? [], true);
                            $synced = 0;
                            $syncedIds = [];
                            $syncedAt = Carbon::now();
                            foreach ($records as $record) {
                                if ($record->seo_sync_status !== 'ready') {
                                    continue;
                                }
                                $product = $record->product;
                                if (!$product) {
                                    continue;
                                }

                                $payload = [];
                                if (!empty($fields['title']) && $record->draft_title) {
                                    $payload['title'] = $record->draft_title;
                                }
                                if (!empty($fields['description']) && $record->draft_description) {
                                    $payload['body_html'] = $record->draft_description;
                                }
                                if (!empty($fields['seo_title']) && $record->draft_seo_title) {
                                    $payload['seo_title'] = $record->draft_seo_title;
                                }
                                if (!empty($fields['seo_description']) && $record->draft_seo_description) {
                                    $payload['seo_description'] = $record->draft_seo_description;
                                }

                                $updatedImage = false;
                                if (!empty($fields['image_alt']) && $record->draft_image_alt_text) {
                                    $image = $product->images()
                                        ->orderBy('position')
                                        ->first();
                                    if ($image) {
                                        $image->update(['alt_text' => $record->draft_image_alt_text]);
                                        $updatedImage = true;
                                    }
                                }

                                if (!$payload && !$updatedImage) {
                                    continue;
                                }

                                if ($payload) {
                                    $product->update($payload);
                                }
                                $synced++;
                                $syncedIds[] = $record->id;
                            }

                            if (!empty($syncedIds)) {
                                StyleProfile::whereIn('id', $syncedIds)->update([
                                    'seo_sync_status' => 'synced',
                                    'seo_synced_at' => $syncedAt,
                                ]);
                            }

                            Notification::make()
                                ->title('SEO sync complete')
                                ->body("Synced {$synced} style(s).")
                                ->success()
                                ->send();
                        }),
                    ExportBulkAction::make()
                        ->exporter(StyleProfileExporter::class)
                        ->visible(fn (): bool => Auth::user()?->hasAnyRole([RolesEnum::SuperAdmin->value, RolesEnum::Admin->value]) ?? false),
                ]),
            ]);
    }

    public static function canCreate(): bool
    {
        return Auth::user()?->hasAnyRole([RolesEnum::SuperAdmin->value, RolesEnum::Admin->value]) ?? false;
    }

    public static function canViewAny(): bool
    {
        return Auth::user()?->hasAnyRole([RolesEnum::SuperAdmin->value, RolesEnum::Admin->value]) ?? false;
    }

    public static function canEdit($record): bool
    {
        return Auth::user()?->hasAnyRole([RolesEnum::SuperAdmin->value, RolesEnum::Admin->value]) ?? false;
    }

    public static function canDelete($record): bool
    {
        return Auth::user()?->hasAnyRole([RolesEnum::SuperAdmin->value, RolesEnum::Admin->value]) ?? false;
    }

    public static function canDeleteAny(): bool
    {
        return Auth::user()?->hasAnyRole([RolesEnum::SuperAdmin->value, RolesEnum::Admin->value]) ?? false;
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListStyleProfiles::route('/'),
        ];
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

    private static function tagOptions(): array
    {
        $raw = Product::query()
            ->whereNotNull('tags')
            ->pluck('tags')
            ->all();

        $tokens = [];
        foreach ($raw as $value) {
            foreach (TagNormalizer::parseTokens($value) as $token) {
                $tokens[$token] = $token;
            }
        }

        ksort($tokens);
        return $tokens;
    }

    private static function colorOptions(): array
    {
        $raw = Product::query()
            ->whereNotNull('color_string')
            ->pluck('color_string')
            ->all();

        $tokens = [];
        foreach ($raw as $value) {
            $parts = preg_split('/[;,]/', (string) $value);
            if (!$parts) {
                continue;
            }

            foreach ($parts as $part) {
                $token = trim((string) $part);
                if ($token === '') {
                    continue;
                }
                $tokens[$token] = $token;
            }
        }

        ksort($tokens);
        return $tokens;
    }

    private static function nullIfEmpty(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }

        $trimmed = trim((string) $value);
        return $trimmed === '' ? null : $trimmed;
    }
}
