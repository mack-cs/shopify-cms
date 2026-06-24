<?php

namespace App\Filament\Resources\ProductResource\RelationManagers;

use App\Enums\RolesEnum;
use App\Filament\Resources\ProductResource;
use App\Models\Image;
use App\Models\NewProductDraft;
use App\Models\Product;
use App\Services\AdminNotification;
use Filament\Notifications\Notification;
use Filament\Forms;
use Filament\Forms\Components\Section;
use Filament\Forms\Get;
use Filament\Tables;
use Filament\Tables\Columns\ImageColumn;
use Filament\Resources\RelationManagers\RelationManager;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\HtmlString;
use Illuminate\Support\Str;

class ImagesRelationManager extends RelationManager
{
    protected static string $relationship = 'allImages';

    public function form(Forms\Form $form): Forms\Form
    {
        return $form->schema([
            Section::make()->schema([
                Forms\Components\CheckboxList::make('associated_image_srcs')
                    ->label('Associated product images')
                    ->helperText('Reuse an image from the single products associated with this bundle or stack.')
                    ->options(fn (): array => $this->associatedProductImageOptions())
                    ->visible(fn (): bool => $this->associatedProductImageOptions() !== [])
                    ->columns(2)
                    ->gridDirection('row')
                    ->extraAttributes([
                        'style' => 'max-height: 22rem; overflow-y: auto; padding: 0.75rem; border: 1px solid rgb(229 231 235); border-radius: 0.5rem; align-content: start;',
                    ])
                    ->allowHtml()
                    ->maxItems(1)
                    ->live()
                    ->dehydrated(false)
                    ->afterStateHydrated(function (Forms\Components\CheckboxList $component, Get $get): void {
                        $src = $this->normalizeImageUrl(is_string($get('src')) ? $get('src') : null);
                        if ($src !== null && array_key_exists($src, $this->associatedProductImageOptions())) {
                            $component->state([$src]);
                        }
                    })
                    ->afterStateUpdated(function ($state, callable $set, Get $get): void {
                        $selected = is_array($state) ? array_values($state) : [];
                        $lastSelected = $selected !== [] ? $selected[array_key_last($selected)] : null;
                        $src = $this->normalizeImageUrl(is_string($lastSelected) ? $lastSelected : null);

                        if ($src !== null) {
                            $set('associated_image_srcs', [$src]);
                            $set('src', $src);
                            $set('image_path', null);
                            return;
                        }

                        $currentSrc = $this->normalizeImageUrl(is_string($get('src')) ? $get('src') : null);
                        if ($currentSrc !== null && array_key_exists($currentSrc, $this->associatedProductImageOptions())) {
                            $set('src', null);
                        }
                    })
                    ->columnSpanFull(),
                Forms\Components\Placeholder::make('associated_image_preview')
                    ->label('Preview')
                    ->content(fn (Get $get): ?HtmlString => $this->associatedProductImagePreview($this->selectedAssociatedImageSrc($get('associated_image_srcs'))))
                    ->visible(fn (Get $get): bool => $this->selectedAssociatedImageSrc($get('associated_image_srcs')) !== null)
                    ->columnSpanFull(),
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
            Tables\Columns\TextColumn::make('position')
                ->sortable()
                ->description(fn (Image $record): ?string => $this->hasDuplicatePosition($record)
                    ? 'Duplicate position'
                    : null)
                ->color(fn (Image $record): string => $this->hasDuplicatePosition($record) ? 'danger' : 'gray'),
            ImageColumn::make('thumbnail')
                ->label('Thumbnail')
                ->square()
                ->size(50)
                ->checkFileExistence(false)
                ->getStateUsing(fn ($record) => $this->normalizeImageUrl($record->src)),
            Tables\Columns\IconColumn::make('duplicate_position')
                ->label('Duplicate')
                ->boolean()
                ->state(fn (Image $record): bool => $this->hasDuplicatePosition($record))
                ->trueColor('danger')
                ->falseColor('success'),
            Tables\Columns\TextColumn::make('shopify_id')
                ->label('Shopify ID')
                ->wrap()
                ->searchable()
                ->toggleable(isToggledHiddenByDefault: true),
            Tables\Columns\TextColumn::make('sync_state')
                ->label('Sync State')
                ->badge()
                ->formatStateUsing(fn (?string $state): string => str_replace('_', ' ', (string) $state))
                ->color(fn (?string $state): string => match ($state) {
                    Image::SYNC_STATE_SYNCED => 'success',
                    Image::SYNC_STATE_LOCAL_NEW,
                    Image::SYNC_STATE_LOCAL_UPDATED => 'warning',
                    Image::SYNC_STATE_CONFLICT,
                    Image::SYNC_STATE_LOCAL_DELETED,
                    Image::SYNC_STATE_REMOTE_DELETED => 'danger',
                    default => 'gray',
                }),
            Tables\Columns\TextColumn::make('backup_status')
                ->label('Backup')
                ->badge()
                ->formatStateUsing(fn (?string $state): string => str_replace('_', ' ', (string) $state))
                ->color(fn (?string $state): string => match ($state) {
                    Image::BACKUP_STATUS_BACKED_UP => 'success',
                    Image::BACKUP_STATUS_PENDING => 'warning',
                    Image::BACKUP_STATUS_FAILED,
                    Image::BACKUP_STATUS_MISSING_SOURCE => 'danger',
                    default => 'gray',
                }),
            Tables\Columns\IconColumn::make('local_dirty')
                ->label('Local Dirty')
                ->boolean()
                ->toggleable(isToggledHiddenByDefault: true),
            Tables\Columns\IconColumn::make('is_duplicate_hidden')
                ->label('Hidden Duplicate')
                ->boolean()
                ->trueColor('warning')
                ->falseColor('success')
                ->visible(fn (): bool => $this->canViewHiddenDuplicates())
                ->toggleable(isToggledHiddenByDefault: true),
            Tables\Columns\TextColumn::make('duplicate_hidden_reason')
                ->label('Hidden Reason')
                ->wrap()
                ->visible(fn (): bool => $this->canViewHiddenDuplicates())
                ->toggleable(isToggledHiddenByDefault: true),
            Tables\Columns\TextColumn::make('last_shopify_seen_at')
                ->label('Last Shopify Seen')
                ->since()
                ->sortable()
                ->toggleable(isToggledHiddenByDefault: true),
            Tables\Columns\TextColumn::make('last_synced_at')
                ->label('Last Synced')
                ->since()
                ->sortable()
                ->toggleable(isToggledHiddenByDefault: true),
            Tables\Columns\TextColumn::make('backup_completed_at')
                ->label('Backed Up')
                ->since()
                ->sortable()
                ->toggleable(isToggledHiddenByDefault: true),
            Tables\Columns\TextColumn::make('backup_filename')
                ->label('Backup Filename')
                ->getStateUsing(fn (Image $record): string => $record->backupFilename())
                ->wrap()
                ->toggleable(isToggledHiddenByDefault: true),
            Tables\Columns\TextColumn::make('approved_filename')
                ->label('Approved Filename')
                ->placeholder('Not set')
                ->wrap()
                ->toggleable(),
            Tables\Columns\IconColumn::make('needs_shopify_image_sync')
                ->label('Needs Shopify Sync')
                ->boolean()
                ->toggleable(),
            Tables\Columns\TextColumn::make('last_shopify_image_synced_at')
                ->label('Image Synced')
                ->since()
                ->sortable()
                ->toggleable(isToggledHiddenByDefault: true),
            Tables\Columns\TextColumn::make('alt_text')
                ->wrap()
                ->toggleable(isToggledHiddenByDefault: true),
        ])->filters([
            Tables\Filters\SelectFilter::make('sync_state')
                ->options([
                    Image::SYNC_STATE_SYNCED => 'Synced',
                    Image::SYNC_STATE_LOCAL_NEW => 'Local New',
                    Image::SYNC_STATE_LOCAL_UPDATED => 'Local Updated',
                    Image::SYNC_STATE_LOCAL_DELETED => 'Local Deleted',
                    Image::SYNC_STATE_REMOTE_DELETED => 'Remote Deleted',
                    Image::SYNC_STATE_CONFLICT => 'Conflict',
                ]),
            Tables\Filters\SelectFilter::make('backup_status')
                ->options([
                    Image::BACKUP_STATUS_PENDING => 'Pending',
                    Image::BACKUP_STATUS_BACKED_UP => 'Backed Up',
                    Image::BACKUP_STATUS_FAILED => 'Failed',
                    Image::BACKUP_STATUS_MISSING_SOURCE => 'Missing Source',
                ]),
            Tables\Filters\TernaryFilter::make('is_duplicate_hidden')
                ->label('Hidden Duplicate')
                ->visible(fn (): bool => $this->canViewHiddenDuplicates()),
        ])->headerActions([
            Tables\Actions\CreateAction::make()
                ->mutateFormDataUsing(fn (array $data): array => $this->normalizeFormData($data))
                ->after(function (): void {
                    $this->bumpOwnerApprovalVersion();
                }),
            Tables\Actions\Action::make('removeDuplicatePositions')
                ->label('Remove Duplicate Positions')
                ->icon('heroicon-o-trash')
                ->color('danger')
                ->requiresConfirmation()
                ->visible(fn (): bool => $this->canManageDuplicateRemoval() && $this->ownerHasDuplicatePositions())
                ->action(function (): void {
                    $summary = $this->removeDuplicatePositionImages();

                    AdminNotification::send(
                        Notification::make()
                            ->title('Duplicate images removed')
                            ->body("Hid {$summary['removed']} duplicate image(s). Kept {$summary['kept']} primary image(s) across {$summary['positions']} duplicate position(s).")
                            ->status($summary['removed'] > 0 ? 'success' : 'warning')
                    );
                }),
            Tables\Actions\Action::make('backupImages')
                ->label('Queue Image Backup')
                ->icon('heroicon-o-arrow-down-tray')
                ->color('gray')
                ->requiresConfirmation()
                ->visible(fn (): bool => $this->getOwnerRecord()->allImages()->exists())
                ->action(function (): void {
                    ProductResource::queueProductImageBackup($this->getOwnerRecord());
                }),
            Tables\Actions\Action::make('logShopifyImageLinks')
                ->label('Log Shopify Image Links')
                ->icon('heroicon-o-bug-ant')
                ->color('gray')
                ->requiresConfirmation()
                ->visible(fn (): bool => Auth::user()?->hasRole(RolesEnum::SuperAdmin->value) ?? false)
                ->action(function (): void {
                    $product = $this->getOwnerRecord();
                    $images = $product->allImages()
                        ->where('sync_state', '!=', Image::SYNC_STATE_LOCAL_DELETED)
                        ->with('imageAsset')
                        ->orderByRaw('CASE WHEN position IS NULL THEN 1 ELSE 0 END')
                        ->orderBy('position')
                        ->orderBy('id')
                        ->get();

                    $payload = $images->map(function (Image $image): array {
                        return [
                            'image_id' => $image->id,
                            'position' => $image->position,
                            'sync_state' => $image->sync_state,
                            'backup_status' => $image->backup_status,
                            'backup_ready' => $image->backupReady(),
                            'needs_shopify_image_sync' => (bool) $image->needs_shopify_image_sync,
                            'approved_filename' => $image->approved_filename,
                            'preferred_filename' => $image->preferredFilename(),
                            'current_src' => $image->src,
                            'backup_public_url' => $image->backupPublicUrl(),
                            'desired_sync_source_url' => $image->desiredSyncSourceUrl(),
                        ];
                    })->values()->all();

                    logger()->info('Debug Shopify image links prepared for product.', [
                        'product_id' => $product->id,
                        'handle' => $product->handle,
                        'title' => $product->title,
                        'images' => $payload,
                    ]);

                    AdminNotification::send(
                        Notification::make()
                            ->title('Shopify image links logged')
                            ->body('The current product image URLs were written to the Laravel log.')
                            ->success()
                    );
                }),
        ])->actions([
            Tables\Actions\EditAction::make()
                ->visible(fn (Image $record): bool => $record->sync_state !== Image::SYNC_STATE_REMOTE_DELETED
                    && !$record->is_duplicate_hidden)
                ->mutateFormDataUsing(fn (array $data): array => $this->normalizeFormData($data))
                ->after(function (): void {
                    $this->bumpOwnerApprovalVersion();
                }),
            Tables\Actions\Action::make('restoreRemoteDeletedImage')
                ->label('Restore to Shopify')
                ->icon('heroicon-o-arrow-path')
                ->color('warning')
                ->requiresConfirmation()
                ->visible(fn (Image $record): bool => $record->sync_state === Image::SYNC_STATE_REMOTE_DELETED
                    && (Auth::user()?->hasRole(RolesEnum::SuperAdmin->value) ?? false))
                ->action(function (Image $record): void {
                    ProductResource::queueRemoteDeletedImageRestore($this->getOwnerRecord(), [$record->id]);
                }),
            Tables\Actions\DeleteAction::make()
                ->visible(fn (Image $record): bool => $record->sync_state !== Image::SYNC_STATE_REMOTE_DELETED
                    && !$record->is_duplicate_hidden)
                ->action(function (Image $record): void {
                    $this->removeImageRecord($record);
                }),
            Tables\Actions\Action::make('restoreHiddenDuplicate')
                ->label('Restore Hidden')
                ->icon('heroicon-o-arrow-uturn-left')
                ->color('warning')
                ->requiresConfirmation()
                ->visible(fn (Image $record): bool => $record->is_duplicate_hidden
                    && $this->canViewHiddenDuplicates())
                ->action(function (Image $record): void {
                    $record->restoreDuplicateHidden(Auth::id());
                    $this->bumpOwnerApprovalVersion();

                    AdminNotification::send(
                        Notification::make()
                            ->title('Hidden duplicate restored')
                            ->body('The image is visible locally again. Sync selected images if it should be restored to Shopify.')
                            ->success()
                    );
                }),
            Tables\Actions\Action::make('removeDuplicate')
                ->label('Hide Duplicate')
                ->icon('heroicon-o-trash')
                ->color('danger')
                ->requiresConfirmation()
                ->visible(fn (Image $record): bool => $this->canManageDuplicateRemoval()
                    && $record->sync_state !== Image::SYNC_STATE_REMOTE_DELETED
                    && !$record->is_duplicate_hidden
                    && $this->hasDuplicatePosition($record))
                ->action(function (Image $record): void {
                    $this->hideDuplicateImageRecord($record);

                    AdminNotification::send(
                        Notification::make()
                            ->title('Duplicate image removed')
                            ->body('The selected duplicate image was hidden from normal image lists.')
                            ->success()
                    );
                }),
        ])->bulkActions([
            Tables\Actions\BulkAction::make('syncSelectedImages')
                ->label('Sync Selected Images to Shopify')
                ->icon('heroicon-o-photo')
                ->color('primary')
                ->requiresConfirmation()
                ->visible(fn (): bool => $this->getOwnerRecord()->isApprovedByTwo() && filled($this->getOwnerRecord()->handle))
                ->action(function (Collection $records): void {
                    ProductResource::queueSelectedImageSync(
                        $this->getOwnerRecord(),
                        $records->pluck('id')->map(fn ($id): int => (int) $id)->all()
                    );
                })
                ->deselectRecordsAfterCompletion(),
            Tables\Actions\BulkAction::make('restoreRemoteDeletedImages')
                ->label('Restore Remote-Deleted Images')
                ->icon('heroicon-o-arrow-path')
                ->color('warning')
                ->requiresConfirmation()
                ->visible(fn (): bool => (Auth::user()?->hasRole(RolesEnum::SuperAdmin->value) ?? false)
                    && filled($this->getOwnerRecord()->handle))
                ->action(function (Collection $records): void {
                    ProductResource::queueRemoteDeletedImageRestore(
                        $this->getOwnerRecord(),
                        $records->pluck('id')->map(fn ($id): int => (int) $id)->all()
                    );
                })
                ->deselectRecordsAfterCompletion(),
            Tables\Actions\BulkAction::make('removeSelectedDuplicates')
                ->label('Remove Selected Duplicates')
                ->icon('heroicon-o-trash')
                ->color('danger')
                ->requiresConfirmation()
                ->visible(fn (): bool => $this->canManageDuplicateRemoval())
                ->action(function (Collection $records): void {
                    $removed = 0;

                    foreach ($records as $record) {
                        if (!$record instanceof Image || !$this->hasDuplicatePosition($record)) {
                            continue;
                        }

                        $this->hideDuplicateImageRecord($record, false);
                        $removed++;
                    }

                    if ($removed > 0) {
                        $this->bumpOwnerApprovalVersion();
                    }

                    AdminNotification::send(
                        Notification::make()
                            ->title('Duplicate images removed')
                            ->body($removed > 0
                                ? "Hid {$removed} selected duplicate image(s)."
                                : 'No selected images were removable duplicates.')
                            ->status($removed > 0 ? 'success' : 'warning')
                    );
                })
                ->deselectRecordsAfterCompletion(),
        ])
            ->modifyQueryUsing(fn (Builder $query): Builder => $this->applyImageVisibilityQuery($query))
            ->defaultSort('position')
            ->paginated(false)
            ->reorderable('position');
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

    /**
     * @return array<string, string>
     */
    private function associatedProductImageOptions(): array
    {
        $productIds = $this->associatedProductIds();
        if ($productIds === []) {
            return [];
        }

        $order = array_flip($productIds);
        $products = Product::query()
            ->whereIn('id', $productIds)
            ->with(['images' => fn ($query) => $query
                ->orderByRaw('CASE WHEN position IS NULL THEN 1 ELSE 0 END')
                ->orderBy('position')
                ->orderBy('id')])
            ->get(['id', 'title', 'handle'])
            ->sortBy(fn (Product $product): int => $order[(int) $product->id] ?? PHP_INT_MAX);

        $options = [];
        foreach ($products as $product) {
            foreach ($product->images as $image) {
                if (!$image instanceof Image) {
                    continue;
                }

                $src = $this->normalizeImageUrl($image->src);
                if ($src === null || isset($options[$src])) {
                    continue;
                }

                $options[$src] = $this->associatedProductImageOptionLabel($product, $image, $src);
            }
        }

        return $options;
    }

    /**
     * @return array<int, int>
     */
    private function associatedProductIds(): array
    {
        $draft = $this->linkedDraftForOwner();
        $ids = is_array($draft?->bundle_product_ids) ? $draft->bundle_product_ids : [];

        $normalized = [];
        $seen = [];

        foreach ($ids as $id) {
            $id = (int) $id;
            if ($id <= 0 || isset($seen[$id])) {
                continue;
            }

            $seen[$id] = true;
            $normalized[] = $id;
        }

        return $normalized;
    }

    private function linkedDraftForOwner(): ?NewProductDraft
    {
        $product = $this->getOwnerRecord();
        if (!$product instanceof Product) {
            return null;
        }

        $shopifyId = trim((string) ($product->shopify_id ?? ''));
        $handle = trim((string) ($product->handle ?? ''));

        if ($shopifyId === '' && $handle === '') {
            return null;
        }

        return NewProductDraft::query()
            ->where(function (Builder $query) use ($shopifyId, $handle): void {
                if ($shopifyId !== '') {
                    $query->where('shopify_id', $shopifyId);
                }

                if ($handle !== '') {
                    $shopifyId !== ''
                        ? $query->orWhere('handle', $handle)
                        : $query->where('handle', $handle);
                }
            })
            ->orderByDesc('updated_at')
            ->first(['id', 'shopify_id', 'handle', 'bundle_product_ids']);
    }

    private function associatedProductImageOptionLabel(Product $product, Image $image, string $src): string
    {
        $label = e($this->associatedProductImageTextLabel($product, $image));
        $src = e($src);

        return <<<HTML
<div style="display:flex;align-items:center;gap:12px;min-height:84px;">
    <img src="{$src}" alt="" style="width:76px;height:76px;object-fit:cover;border-radius:6px;border:1px solid #e5e7eb;background:#f9fafb;flex:0 0 auto;" />
    <span style="white-space:normal;line-height:1.25;">{$label}</span>
</div>
HTML;
    }

    private function associatedProductImageTextLabel(Product $product, Image $image): string
    {
        $title = trim((string) ($product->title ?? ''));
        $handle = trim((string) ($product->handle ?? ''));
        $label = $title !== '' ? $title : ($handle !== '' ? $handle : 'Product #' . $product->id);

        $position = $image->position !== null ? '#' . $image->position : '#?';

        return trim($label . ' ' . $position);
    }

    private function selectedAssociatedImageSrc(mixed $state): ?string
    {
        $selected = is_array($state) ? array_values($state) : [];
        $lastSelected = $selected !== [] ? $selected[array_key_last($selected)] : null;

        return $this->normalizeImageUrl(is_string($lastSelected) ? $lastSelected : null);
    }

    private function associatedProductImagePreview(mixed $src): ?HtmlString
    {
        $src = $this->normalizeImageUrl(is_string($src) ? $src : null);
        if ($src === null) {
            return null;
        }

        $label = $this->associatedProductImagePreviewLabel($src);
        $escapedSrc = e($src);
        $escapedLabel = e($label ?? 'Selected associated product image');

        return new HtmlString(<<<HTML
<div style="display:flex;align-items:center;gap:14px;">
    <img src="{$escapedSrc}" alt="" style="width:128px;height:128px;object-fit:cover;border-radius:8px;border:1px solid #e5e7eb;background:#f9fafb;" />
    <div style="font-size:14px;line-height:1.4;color:#374151;">{$escapedLabel}</div>
</div>
HTML);
    }

    private function associatedProductImagePreviewLabel(string $src): ?string
    {
        $productIds = $this->associatedProductIds();
        if ($productIds === []) {
            return null;
        }

        $products = Product::query()
            ->whereIn('id', $productIds)
            ->with(['images' => fn ($query) => $query
                ->orderByRaw('CASE WHEN position IS NULL THEN 1 ELSE 0 END')
                ->orderBy('position')
                ->orderBy('id')])
            ->get(['id', 'title', 'handle']);

        foreach ($products as $product) {
            foreach ($product->images as $image) {
                if (!$image instanceof Image || $this->normalizeImageUrl($image->src) !== $src) {
                    continue;
                }

                return $this->associatedProductImageTextLabel($product, $image);
            }
        }

        return null;
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

    private function hasDuplicatePosition(Image $record): bool
    {
        $position = $record->position;
        if ($position === null) {
            return false;
        }

        return $this->getOwnerRecord()
            ?->allImages()
            ->whereKeyNot($record->id)
            ->where('position', $position)
            ->whereNotIn('sync_state', [
                Image::SYNC_STATE_LOCAL_DELETED,
                Image::SYNC_STATE_REMOTE_DELETED,
            ])
            ->where(function (Builder $query): void {
                $query->whereNull('is_duplicate_hidden')
                    ->orWhere('is_duplicate_hidden', false);
            })
            ->exists() ?? false;
    }

    private function canViewHiddenDuplicates(): bool
    {
        return Auth::user()?->hasRole(RolesEnum::SuperAdmin->value) ?? false;
    }

    private function canManageDuplicateRemoval(): bool
    {
        return Auth::user()?->hasRole(RolesEnum::SuperAdmin->value) ?? false;
    }

    private function applyImageVisibilityQuery(Builder $query): Builder
    {
        if ($this->canViewHiddenDuplicates()) {
            return $query;
        }

        return $query
            ->whereNotIn('sync_state', [
                Image::SYNC_STATE_LOCAL_DELETED,
                Image::SYNC_STATE_REMOTE_DELETED,
            ])
            ->where(function (Builder $imageQuery): void {
                $imageQuery->whereNull('is_duplicate_hidden')
                    ->orWhere('is_duplicate_hidden', false);
            });
    }

    private function ownerHasDuplicatePositions(): bool
    {
        $product = $this->getOwnerRecord();
        if (!$product instanceof Product) {
            return false;
        }

        return $product->allImages()
            ->whereNotNull('position')
            ->whereNotIn('sync_state', [
                Image::SYNC_STATE_LOCAL_DELETED,
                Image::SYNC_STATE_REMOTE_DELETED,
            ])
            ->where(function (Builder $query): void {
                $query->whereNull('is_duplicate_hidden')
                    ->orWhere('is_duplicate_hidden', false);
            })
            ->select('position')
            ->groupBy('position')
            ->havingRaw('COUNT(*) > 1')
            ->exists();
    }

    /**
     * @return array{removed:int,kept:int,positions:int}
     */
    private function removeDuplicatePositionImages(): array
    {
        $product = $this->getOwnerRecord();
        if (!$product instanceof Product) {
            return ['removed' => 0, 'kept' => 0, 'positions' => 0];
        }

        $duplicateGroups = $product->allImages()
            ->whereNotNull('position')
            ->whereNotIn('sync_state', [
                Image::SYNC_STATE_LOCAL_DELETED,
                Image::SYNC_STATE_REMOTE_DELETED,
            ])
            ->where(function (Builder $query): void {
                $query->whereNull('is_duplicate_hidden')
                    ->orWhere('is_duplicate_hidden', false);
            })
            ->orderBy('position')
            ->orderBy('id')
            ->get()
            ->groupBy(fn (Image $image): string => (string) $image->position)
            ->filter(fn (Collection $images): bool => $images->count() > 1);

        $removed = 0;
        $kept = 0;

        foreach ($duplicateGroups as $images) {
            $primary = $this->preferredImageToKeep($images);
            if (!$primary instanceof Image) {
                continue;
            }

            $kept++;

            foreach ($images as $image) {
                if (!$image instanceof Image || $image->is($primary)) {
                    continue;
                }

                $this->hideDuplicateImageRecord($image, false, $primary);
                $removed++;
            }
        }

        if ($removed > 0) {
            $this->bumpOwnerApprovalVersion();
        }

        return [
            'removed' => $removed,
            'kept' => $kept,
            'positions' => $duplicateGroups->count(),
        ];
    }

    private function preferredImageToKeep(Collection $images): ?Image
    {
        /** @var Collection<int, Image> $sorted */
        $sorted = $images->sort(function (Image $left, Image $right): int {
            $leftRank = [
                $left->sync_state === Image::SYNC_STATE_SYNCED ? 0 : 1,
                $left->backup_status === Image::BACKUP_STATUS_BACKED_UP ? 0 : 1,
                blank($left->shopify_id) ? 1 : 0,
                (int) $left->id,
            ];
            $rightRank = [
                $right->sync_state === Image::SYNC_STATE_SYNCED ? 0 : 1,
                $right->backup_status === Image::BACKUP_STATUS_BACKED_UP ? 0 : 1,
                blank($right->shopify_id) ? 1 : 0,
                (int) $right->id,
            ];

            return $leftRank <=> $rightRank;
        });

        $first = $sorted->first();

        return $first instanceof Image ? $first : null;
    }

    private function hideDuplicateImageRecord(Image $record, bool $bumpApprovalVersion = true, ?Image $primary = null): void
    {
        $primary ??= $this->primaryImageForDuplicatePosition($record);
        $record->hideAsDuplicate(
            $primary,
            Auth::id(),
            $record->position !== null
                ? "Duplicate image position {$record->position}"
                : 'Duplicate image cleanup'
        );

        if ($bumpApprovalVersion) {
            $this->bumpOwnerApprovalVersion();
        }
    }

    private function primaryImageForDuplicatePosition(Image $record): ?Image
    {
        if ($record->position === null) {
            return null;
        }

        $images = $this->getOwnerRecord()
            ?->allImages()
            ->where('position', $record->position)
            ->whereNotIn('sync_state', [
                Image::SYNC_STATE_LOCAL_DELETED,
                Image::SYNC_STATE_REMOTE_DELETED,
            ])
            ->where(function (Builder $query): void {
                $query->whereNull('is_duplicate_hidden')
                    ->orWhere('is_duplicate_hidden', false);
            })
            ->get() ?? collect();

        $primary = $this->preferredImageToKeep($images);

        return $primary instanceof Image && !$primary->is($record) ? $primary : null;
    }

    private function removeImageRecord(Image $record, bool $bumpApprovalVersion = true): void
    {
        if (blank($record->shopify_id)) {
            $record->delete();
        } else {
            $record->update([
                'sync_state' => Image::SYNC_STATE_LOCAL_DELETED,
                'local_dirty' => true,
            ]);
        }

        if ($bumpApprovalVersion) {
            $this->bumpOwnerApprovalVersion();
        }
    }
}
