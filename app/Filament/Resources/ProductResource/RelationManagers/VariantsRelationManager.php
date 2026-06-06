<?php

namespace App\Filament\Resources\ProductResource\RelationManagers;

use App\Models\Image;
use App\Models\Product;
use App\Models\ShopifyRow;
use App\Models\Variant;
use App\Services\AdminNotification;
use App\Services\HeaderStore;
use App\Services\RowKey;
use Filament\Forms;
use Filament\Forms\Components\Section;
use Filament\Forms\Get;
use Filament\Notifications\Notification;
use Filament\Tables;
use Filament\Tables\Columns\ImageColumn;
use Filament\Resources\RelationManagers\RelationManager;
use Illuminate\Support\HtmlString;
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
            Tables\Columns\TextColumn::make('variant_conflict_details')
                ->label('Conflict Details')
                ->state(fn (Variant $record): string => $this->variantConflictSummary($record))
                ->wrap()
                ->color(fn (Variant $record): string => $record->sync_state === Variant::SYNC_STATE_CONFLICT ? 'warning' : 'gray')
                ->tooltip(fn (Variant $record): string => $this->variantConflictSummary($record))
                ->toggleable(),
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
                    $this->bumpOwnerApprovalVersion();

                    return $variant;
                }),
        ])->actions([
            Tables\Actions\EditAction::make()
                ->using(function (array $data, Variant $record): Variant {
                    $imagePayload = $this->pullVariantImagePayload($data);
                    $record->update($data);
                    $this->syncVariantImage($record, $imagePayload);
                    $this->bumpOwnerApprovalVersion();

                    return $record;
                }),
            Tables\Actions\Action::make('useShopifyVariantValues')
                ->label('Use Shopify Values')
                ->icon('heroicon-o-arrow-down-tray')
                ->color('warning')
                ->requiresConfirmation()
                ->modalHeading('Use Shopify variant values?')
                ->modalContent(fn (Variant $record): HtmlString => $this->variantConflictModalHtml($record))
                ->visible(fn (Variant $record): bool => $record->sync_state === Variant::SYNC_STATE_CONFLICT
                    && $this->importedVariantRowForRecord($record) instanceof ShopifyRow)
                ->action(function (Variant $record): void {
                    $row = $this->importedVariantRowForRecord($record);
                    if (!$row instanceof ShopifyRow) {
                        AdminNotification::send(
                            Notification::make()
                                ->title('Shopify values not found')
                                ->body('No latest imported Shopify row could be matched to this variant.')
                                ->warning()
                        );

                        return;
                    }

                    $this->applyImportedVariantRow($record, $row);

                    AdminNotification::send(
                        Notification::make()
                            ->title('Shopify variant values applied')
                            ->success()
                    );
                }),
            Tables\Actions\Action::make('keepLocalVariantValues')
                ->label('Keep Local Values')
                ->icon('heroicon-o-arrow-up-tray')
                ->color('gray')
                ->requiresConfirmation()
                ->modalHeading('Keep local variant values?')
                ->modalContent(fn (Variant $record): HtmlString => $this->variantConflictModalHtml($record))
                ->visible(fn (Variant $record): bool => $record->sync_state === Variant::SYNC_STATE_CONFLICT)
                ->action(function (Variant $record): void {
                    $this->keepLocalVariantValues($record);

                    AdminNotification::send(
                        Notification::make()
                            ->title('Local variant values kept')
                            ->body('The conflict was converted back to a local update so it can be synced to Shopify.')
                            ->success()
                    );
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

                    $this->bumpOwnerApprovalVersion();
                }),
        ])->modifyQueryUsing(fn ($query) => $query->with('image'));
    }

    private function variantConflictSummary(Variant $variant): string
    {
        if ($variant->sync_state !== Variant::SYNC_STATE_CONFLICT) {
            return '';
        }

        $row = $this->importedVariantRowForRecord($variant);
        if (!$row instanceof ShopifyRow) {
            return 'No latest imported Shopify row could be matched to this variant.';
        }

        $differences = $this->variantConflictDifferences($variant, $row);
        if ($differences === []) {
            return 'Conflict state is set, but the displayed fields match the latest imported Shopify row.';
        }

        return implode('; ', array_map(
            fn (array $difference): string => "{$difference['label']}: local {$difference['local_value']} / Shopify {$difference['shopify_value']}",
            $differences
        ));
    }

    private function variantConflictModalHtml(Variant $variant): HtmlString
    {
        $summary = $this->variantConflictSummary($variant);
        $row = $this->importedVariantRowForRecord($variant);
        $differences = $row instanceof ShopifyRow
            ? $this->variantConflictDifferences($variant, $row)
            : [];

        if ($differences === []) {
            return new HtmlString(
                "<div class='text-sm text-gray-700'>"
                . e($summary !== '' ? $summary : 'No conflict details to show.')
                . '</div>'
            );
        }

        $items = array_map(function (array $difference): string {
            $label = e($difference['label']);
            $localValue = e($difference['local_value']);
            $shopifyValue = e($difference['shopify_value']);

            return "<li><strong>{$label}</strong>: local has <code>{$localValue}</code> but <strong>Shopify</strong> imported <code>{$shopifyValue}</code>.</li>";
        }, $differences);

        return new HtmlString(
            "<div class='rounded-xl border border-warning-300 bg-warning-50 p-4 text-sm text-warning-900'>"
            . "<p class='font-semibold mb-2'>Review the variant differences before resolving this conflict.</p>"
            . "<ul class='list-disc pl-5 space-y-1'>"
            . implode('', $items)
            . '</ul>'
            . '</div>'
        );
    }

    /**
     * @return array<int, array{label:string,local_value:string,shopify_value:string}>
     */
    private function variantConflictDifferences(Variant $variant, ShopifyRow $row): array
    {
        $tracked = $this->toBoolean($row->get(HeaderStore::INTERNAL_VARIANT_INVENTORY_TRACKED, null));
        $sku = $this->trimToNull($row->get(HeaderStore::VARIANT_SKU, null));

        $fields = [
            ['label' => 'SKU', 'type' => 'string', 'local' => $variant->sku, 'shopify' => $sku],
            ['label' => 'Barcode', 'type' => 'string', 'local' => $variant->barcode, 'shopify' => $this->trimToNull($row->get(HeaderStore::VARIANT_BARCODE, null)) ?? $sku],
            ['label' => 'Price', 'type' => 'decimal2', 'local' => $variant->price, 'shopify' => $row->get(HeaderStore::VARIANT_PRICE, null)],
            ['label' => 'Compare-at price', 'type' => 'decimal2', 'local' => $variant->compare_at_price, 'shopify' => $row->get(HeaderStore::VARIANT_COMPARE_AT, null)],
            ['label' => 'Inventory tracked', 'type' => 'boolean', 'local' => $variant->inventory_tracked, 'shopify' => $tracked],
            ['label' => 'Inventory', 'type' => 'integer', 'local' => $variant->inventory_qty, 'shopify' => $tracked === false ? null : $row->get(HeaderStore::VARIANT_INVENTORY_QTY, null)],
            ['label' => 'Weight', 'type' => 'decimal3', 'local' => $variant->weight, 'shopify' => $row->get(HeaderStore::VARIANT_GRAMS, null)],
            ['label' => 'Weight unit', 'type' => 'string', 'local' => $variant->weight_unit, 'shopify' => $this->trimToNull($row->get(HeaderStore::VARIANT_WEIGHT_UNIT, null)) ?? 'g'],
            ['label' => 'Option 1 name', 'type' => 'string', 'local' => $variant->option1_name, 'shopify' => $row->get(HeaderStore::OPTION1_NAME, null)],
            ['label' => 'Option 1 value', 'type' => 'string', 'local' => $variant->option1_value, 'shopify' => $row->get(HeaderStore::OPTION1_VALUE, null)],
            ['label' => 'Option 2 name', 'type' => 'string', 'local' => $variant->option2_name, 'shopify' => $row->get(HeaderStore::OPTION2_NAME, null)],
            ['label' => 'Option 2 value', 'type' => 'string', 'local' => $variant->option2_value, 'shopify' => $row->get(HeaderStore::OPTION2_VALUE, null)],
            ['label' => 'Option 3 name', 'type' => 'string', 'local' => $variant->option3_name, 'shopify' => $row->get(HeaderStore::OPTION3_NAME, null)],
            ['label' => 'Option 3 value', 'type' => 'string', 'local' => $variant->option3_value, 'shopify' => $row->get(HeaderStore::OPTION3_VALUE, null)],
        ];

        $differences = [];
        foreach ($fields as $field) {
            $type = (string) $field['type'];
            $local = $this->normalizeVariantConflictValue($type, $field['local']);
            $shopify = $this->normalizeVariantConflictValue($type, $field['shopify']);

            if ($local === $shopify) {
                continue;
            }

            $differences[] = [
                'label' => (string) $field['label'],
                'local_value' => $this->formatVariantConflictValue($type, $field['local']),
                'shopify_value' => $this->formatVariantConflictValue($type, $field['shopify']),
            ];
        }

        return $differences;
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

    private function importedVariantRowForRecord(Variant $variant): ?ShopifyRow
    {
        $product = $this->getOwnerRecord();
        if (!$product instanceof Product) {
            return null;
        }

        $rows = ShopifyRow::query()
            ->where('import_id', $product->import_id)
            ->where('handle', $product->handle)
            ->whereNotNull('variant_key')
            ->orderBy('row_index')
            ->get();

        $shopifyId = $this->trimToNull($variant->shopify_id);
        if ($shopifyId !== null) {
            $match = $rows->first(fn (ShopifyRow $row): bool => $this->trimToNull(
                $row->get(HeaderStore::INTERNAL_VARIANT_SHOPIFY_ID, null)
            ) === $shopifyId);

            if ($match instanceof ShopifyRow) {
                return $match;
            }
        }

        $variantKey = $this->variantKeyForRecord($variant);
        if ($variantKey === null) {
            return null;
        }

        $match = $rows->first(fn (ShopifyRow $row): bool => trim((string) ($row->variant_key ?? '')) === $variantKey);

        return $match instanceof ShopifyRow ? $match : null;
    }

    private function applyImportedVariantRow(Variant $variant, ShopifyRow $row): void
    {
        $sku = $this->trimToNull($row->get(HeaderStore::VARIANT_SKU, null));
        $tracked = $this->toBoolean($row->get(HeaderStore::INTERNAL_VARIANT_INVENTORY_TRACKED, null));

        $updates = [
            'shopify_id' => $this->trimToNull($row->get(HeaderStore::INTERNAL_VARIANT_SHOPIFY_ID, null)) ?? $variant->shopify_id,
            'sku' => $sku,
            'barcode' => $this->trimToNull($row->get(HeaderStore::VARIANT_BARCODE, null)) ?? $sku,
            'weight' => $this->toDecimal($row->get(HeaderStore::VARIANT_GRAMS, null)),
            'weight_unit' => $this->trimToNull($row->get(HeaderStore::VARIANT_WEIGHT_UNIT, null)) ?? 'g',
            'inventory_tracked' => $tracked,
            'inventory_qty' => $tracked === false
                ? null
                : $this->toInteger($row->get(HeaderStore::VARIANT_INVENTORY_QTY, null)),
            'option1_name' => $row->get(HeaderStore::OPTION1_NAME, null),
            'option1_value' => $row->get(HeaderStore::OPTION1_VALUE, null),
            'option2_name' => $row->get(HeaderStore::OPTION2_NAME, null),
            'option2_value' => $row->get(HeaderStore::OPTION2_VALUE, null),
            'option3_name' => $row->get(HeaderStore::OPTION3_NAME, null),
            'option3_value' => $row->get(HeaderStore::OPTION3_VALUE, null),
            'price' => $this->toDecimal($row->get(HeaderStore::VARIANT_PRICE, null)),
            'compare_at_price' => $this->toDecimal($row->get(HeaderStore::VARIANT_COMPARE_AT, null)),
            'sync_state' => Variant::SYNC_STATE_SYNCED,
            'local_dirty' => false,
            'inventory_local_dirty' => false,
            'inventory_sync_error' => null,
            'last_shopify_seen_at' => now(),
            'last_synced_at' => now(),
            'inventory_last_synced_at' => now(),
        ];

        Variant::withoutEvents(function () use ($variant, $updates): void {
            $variant->forceFill($updates)->save();
        });
    }

    private function keepLocalVariantValues(Variant $variant): void
    {
        Variant::withoutEvents(function () use ($variant): void {
            $variant->forceFill([
                'sync_state' => Variant::SYNC_STATE_LOCAL_UPDATED,
                'local_dirty' => true,
            ])->save();
        });
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

    private function variantKeyForRecord(Variant $variant): ?string
    {
        return RowKey::variantKey([
            HeaderStore::VARIANT_SKU => $variant->sku,
            HeaderStore::OPTION1_VALUE => $variant->option1_value,
            HeaderStore::OPTION2_VALUE => $variant->option2_value,
            HeaderStore::OPTION3_VALUE => $variant->option3_value,
        ]);
    }

    private function toDecimal(mixed $value): ?float
    {
        if ($value === null) {
            return null;
        }

        $normalized = str_replace(' ', '', trim((string) $value));
        if ($normalized === '') {
            return null;
        }

        if (str_contains($normalized, ',') && !str_contains($normalized, '.')) {
            $normalized = str_replace(',', '.', $normalized);
        } else {
            $normalized = str_replace(',', '', $normalized);
        }

        return is_numeric($normalized) ? (float) $normalized : null;
    }

    private function toInteger(mixed $value): ?int
    {
        if ($value === null) {
            return null;
        }

        $normalized = trim((string) $value);

        return is_numeric($normalized) ? (int) $normalized : null;
    }

    private function toBoolean(mixed $value): ?bool
    {
        if ($value === null) {
            return null;
        }

        if (is_bool($value)) {
            return $value;
        }

        return match (strtolower(trim((string) $value))) {
            '1', 'true', 'yes', 'y' => true,
            '0', 'false', 'no', 'n' => false,
            default => null,
        };
    }

    private function normalizeVariantConflictValue(string $type, mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }

        if ($type === 'boolean') {
            if (is_bool($value)) {
                return $value ? 'true' : 'false';
            }

            $bool = $this->toBoolean($value);

            return $bool === null ? null : ($bool ? 'true' : 'false');
        }

        $string = trim((string) $value);
        if ($string === '') {
            return null;
        }

        if ($type === 'integer') {
            return is_numeric($string) ? (string) (int) $string : $string;
        }

        if ($type === 'decimal2' || $type === 'decimal3') {
            $precision = $type === 'decimal2' ? 2 : 3;
            $normalized = str_replace(' ', '', $string);
            if (str_contains($normalized, ',') && !str_contains($normalized, '.')) {
                $normalized = str_replace(',', '.', $normalized);
            } else {
                $normalized = str_replace(',', '', $normalized);
            }

            return is_numeric($normalized)
                ? number_format((float) $normalized, $precision, '.', '')
                : $string;
        }

        return $string;
    }

    private function formatVariantConflictValue(string $type, mixed $value): string
    {
        $normalized = $this->normalizeVariantConflictValue($type, $value);

        return $normalized === null ? 'blank' : $normalized;
    }

    private function bumpOwnerApprovalVersion(): void
    {
        /** @var Product|null $product */
        $product = $this->getOwnerRecord();
        if (!$product) {
            return;
        }

        Product::withoutEvents(function () use ($product): void {
            $product->forceFill([
                'approval_version' => ((int) ($product->approval_version ?? 1)) + 1,
            ])->save();
        });
    }
}
