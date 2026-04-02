<?php

namespace App\Filament\Resources;

use App\Filament\Resources\PendingDropdownOptionResource\Pages;
use App\Jobs\ProcessPendingDropdownOptionsJob;
use App\Models\ChangeLog;
use App\Models\DropdownOption;
use App\Models\Product;
use App\Models\ShopifyRow;
use App\Enums\RolesEnum;
use App\Services\Normalizer;
use App\Services\HeaderStore;
use App\Services\DropdownCollectionCatalog;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Columns\ToggleColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Notifications\Notification;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Auth;

class PendingDropdownOptionResource extends Resource
{
    protected static ?string $model = DropdownOption::class;
    protected static ?string $navigationIcon = 'heroicon-o-exclamation-triangle';
    protected static ?string $navigationGroup = 'Configurations';
    protected static ?string $navigationLabel = 'Pending Dropdown Values';
    protected static ?int $navigationSort = 5;
    private static bool $cleanedUnknownPendingRows = false;

    public static function table(Table $table): Table
    {
        self::cleanupUnknownPendingRows();

        return $table
            ->modifyQueryUsing(fn (Builder $query): Builder => $query
                ->where('active', false)
                ->orderBy('header')
                ->orderBy('value'))
            ->columns([
                TextColumn::make('header')->searchable()->wrap(),
                TextColumn::make('value')->searchable()->wrap(),
                TextColumn::make('collection_style')
                    ->label('Collection')
                    ->state(function (DropdownOption $record): string {
                        return self::collectionLabel($record);
                    }),
                TextColumn::make('collection_tag_primary')->label('Tag 1')->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('collection_tag_secondary')->label('Tag 2')->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('usage_count')
                    ->label('Used by')
                    ->state(fn (DropdownOption $record): int => self::usageCount($record))
                    ->toggleable(isToggledHiddenByDefault: true),
                ToggleColumn::make('active')->disabled(),
            ])
            ->filters([
                SelectFilter::make('header')
                    ->label('Header')
                    ->options(fn () => DropdownOption::query()
                        ->select('header')
                        ->distinct()
                        ->orderBy('header')
                        ->pluck('header', 'header')
                        ->all()),
                SelectFilter::make('collection_style')
                    ->label('Collection')
                    ->options(function (): array {
                        $styles = collect(app(DropdownCollectionCatalog::class)->contexts())
                            ->pluck('collection_style')
                            ->filter(fn ($value) => is_string($value) && trim($value) !== '')
                            ->map(fn (string $value): string => trim($value))
                            ->unique()
                            ->sort()
                            ->values()
                            ->all();

                        return array_combine($styles, $styles) ?: [];
                    })
                    ->query(function (Builder $query, array $data): Builder {
                        $selected = trim((string) ($data['value'] ?? ''));
                        if ($selected === '') {
                            return $query;
                        }

                        $contexts = collect(app(DropdownCollectionCatalog::class)->contexts())
                            ->filter(fn (array $ctx): bool => strcasecmp((string) ($ctx['collection_style'] ?? ''), $selected) === 0)
                            ->values();

                        return $query->where(function (Builder $inner) use ($selected, $contexts): void {
                            $inner->whereRaw('LOWER(collection_style) = ?', [strtolower($selected)]);

                            foreach ($contexts as $ctx) {
                                $primary = trim((string) ($ctx['tag_primary'] ?? ''));
                                if ($primary === '') {
                                    continue;
                                }
                                $secondary = trim((string) ($ctx['tag_secondary'] ?? ''));

                                $inner->orWhere(function (Builder $branch) use ($primary, $secondary): void {
                                    $branch->where(function (Builder $style): void {
                                        $style->whereNull('collection_style')
                                            ->orWhere('collection_style', '');
                                    });
                                    $branch->whereRaw('LOWER(collection_tag_primary) = ?', [strtolower($primary)]);
                                    if ($secondary !== '') {
                                        $branch->whereRaw('LOWER(collection_tag_secondary) = ?', [strtolower($secondary)]);
                                    }
                                });
                            }
                        });
                    }),
            ])
            ->actions([
                Tables\Actions\Action::make('approve')
                    ->label('Approve')
                    ->icon('heroicon-o-check')
                    ->requiresConfirmation()
                    ->disabled(fn (DropdownOption $record): bool => blank(self::resolvedCollectionStyle($record)) || blank($record->collection_tag_primary))
                    ->action(function (DropdownOption $record): void {
                        ProcessPendingDropdownOptionsJob::dispatch([$record->id], 'approve', Auth::id());
                        self::sendQueuedNotification('Approve queued', 'Pending dropdown approval is running in background.');
                    }),
                Tables\Actions\Action::make('reject')
                    ->label('Reject')
                    ->icon('heroicon-o-trash')
                    ->color('danger')
                    ->requiresConfirmation()
                    ->action(function (DropdownOption $record): void {
                        ProcessPendingDropdownOptionsJob::dispatch([$record->id], 'reject', Auth::id());
                        self::sendQueuedNotification('Reject queued', 'Pending dropdown rejection is running in background.');
                    }),
            ])
            ->bulkActions([
                Tables\Actions\BulkAction::make('bulkApprove')
                    ->label('Bulk Approve')
                    ->icon('heroicon-o-check-badge')
                    ->requiresConfirmation()
                    ->action(function ($records): void {
                        $ids = collect($records)->pluck('id')->filter()->map(fn ($id): int => (int) $id)->values()->all();
                        if (empty($ids)) {
                            return;
                        }

                        ProcessPendingDropdownOptionsJob::dispatch($ids, 'approve', Auth::id());
                        self::sendQueuedNotification('Bulk approve queued', 'Pending dropdown approvals are running in background.');
                    })
                    ->deselectRecordsAfterCompletion(),
                Tables\Actions\BulkAction::make('bulkReject')
                    ->label('Bulk Reject')
                    ->icon('heroicon-o-trash')
                    ->color('danger')
                    ->requiresConfirmation()
                    ->action(function ($records): void {
                        $ids = collect($records)->pluck('id')->filter()->map(fn ($id): int => (int) $id)->values()->all();
                        if (empty($ids)) {
                            return;
                        }

                        ProcessPendingDropdownOptionsJob::dispatch($ids, 'reject', Auth::id());
                        self::sendQueuedNotification('Bulk reject queued', 'Pending dropdown rejections are running in background.');
                    })
                    ->deselectRecordsAfterCompletion(),
            ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListPendingDropdownOptions::route('/'),
        ];
    }

    public static function canCreate(): bool
    {
        return false;
    }

    public static function canEdit($record): bool
    {
        return false;
    }

    public static function canDelete($record): bool
    {
        return false;
    }

    public static function canViewAny(): bool
    {
        return Auth::user()?->hasAnyRole([
            RolesEnum::SuperAdmin->value,
            RolesEnum::Admin->value,
        ]) ?? false;
    }

    private static function logChange(DropdownOption $record, string $field, string $old, string $new, ?int $userId = null): void
    {
        ChangeLog::create([
            'changed_by' => $userId ?? Auth::id(),
            'model_type' => DropdownOption::class,
            'model_id' => $record->id,
            'field' => $field,
            'old_value' => $old,
            'new_value' => $new,
        ]);
    }

    private static function usageCount(DropdownOption $record): int
    {
        $tagPrimary = $record->collection_tag_primary;
        $tagSecondary = $record->collection_tag_secondary;
        $header = $record->header;
        $value = $record->value;

        $query = ShopifyRow::query()
            ->join('products', function ($join): void {
                $join->on('shopify_rows.import_id', '=', 'products.import_id')
                    ->on('shopify_rows.handle', '=', 'products.handle');
            })
            ->where('shopify_rows.row_type', 'product_primary')
            ->whereRaw(
                "JSON_UNQUOTE(JSON_EXTRACT(shopify_rows.data, CONCAT('$.\"', REPLACE(?, '\"', '\\\\\"'), '\"'))) = ?",
                [$header, $value]
            );

        if ($tagPrimary) {
            $query->whereRaw(
                "FIND_IN_SET(?, REPLACE(products.tags, ', ', ','))",
                [$tagPrimary]
            );
        }
        if ($tagSecondary) {
            $query->whereRaw(
                "FIND_IN_SET(?, REPLACE(products.tags, ', ', ','))",
                [$tagSecondary]
            );
        }

        return (int) $query->count();
    }

    private static function recalculateErrorsForOption(DropdownOption $record): void
    {
        $productIds = self::affectedProductIds(
            $record->header,
            $record->value,
            $record->collection_tag_primary,
            $record->collection_tag_secondary
        );
        if (empty($productIds)) {
            return;
        }

        $normalizer = app(Normalizer::class);
        Product::whereIn('id', $productIds)->chunkById(200, function ($products) use ($normalizer): void {
            foreach ($products as $product) {
                $normalizer->recalculateErrorsForProduct($product);
            }
        });
    }

    private static function recalculateAllProductErrors(): void
    {
        $normalizer = app(Normalizer::class);
        Product::query()->chunkById(200, function ($products) use ($normalizer): void {
            foreach ($products as $product) {
                $normalizer->recalculateErrorsForProduct($product);
            }
        });
    }

    private static function recalculateErrorsForOptionData(
        string $header,
        string $value,
        ?string $tagPrimary,
        ?string $tagSecondary
    ): void {
        $productIds = self::affectedProductIds($header, $value, $tagPrimary, $tagSecondary);
        if (empty($productIds)) {
            return;
        }

        $normalizer = app(Normalizer::class);
        Product::whereIn('id', $productIds)->chunkById(200, function ($products) use ($normalizer): void {
            foreach ($products as $product) {
                $normalizer->recalculateErrorsForProduct($product);
            }
        });
    }

    private static function affectedProductIds(
        string $header,
        string $value,
        ?string $tagPrimary,
        ?string $tagSecondary
    ): array
    {
        $query = ShopifyRow::query()
            ->join('products', function ($join): void {
                $join->on('shopify_rows.import_id', '=', 'products.import_id')
                    ->on('shopify_rows.handle', '=', 'products.handle');
            })
            ->where('shopify_rows.row_type', 'product_primary')
            ->whereRaw(
                "JSON_UNQUOTE(JSON_EXTRACT(shopify_rows.data, CONCAT('$.\"', REPLACE(?, '\"', '\\\\\"'), '\"'))) = ?",
                [$header, $value]
            );

        if ($tagPrimary) {
            $query->whereRaw(
                "FIND_IN_SET(?, REPLACE(products.tags, ', ', ','))",
                [$tagPrimary]
            );
        }
        if ($tagSecondary) {
            $query->whereRaw(
                "FIND_IN_SET(?, REPLACE(products.tags, ', ', ','))",
                [$tagSecondary]
            );
        }

        return $query->pluck('products.id')->all();
    }

    public static function approveForApplicableCollections(
        DropdownOption $record,
        ?int $userId = null,
        bool $recalculateErrors = true
    ): void
    {
        if (blank($record->collection_style)) {
            $resolved = self::resolvedCollectionStyle($record);
            if ($resolved !== null) {
                $record->update(['collection_style' => $resolved]);
                $record->refresh();
            }
        }

        $contexts = self::applicableCollectionContexts($record->header);
        if (empty($contexts)) {
            return;
        }

        foreach ($contexts as $ctx) {
            $existing = DropdownOption::query()
                ->where('header', $record->header)
                ->whereRaw('LOWER(value) = ?', [strtolower((string) $record->value)])
                ->where('collection_tag_primary', $ctx['tag_primary'])
                ->where(function ($query) use ($ctx): void {
                    if ($ctx['tag_secondary'] !== null) {
                        $query->where('collection_tag_secondary', $ctx['tag_secondary']);
                    } else {
                        $query->whereNull('collection_tag_secondary');
                    }
                })
                ->first();

            if ($existing) {
                if (!$existing->active || $existing->collection_style !== $ctx['collection_style']) {
                    $existing->update([
                        'active' => true,
                        'collection_style' => $ctx['collection_style'],
                    ]);
                }
                continue;
            }

            DropdownOption::create([
                'header' => $record->header,
                'value' => $record->value,
                'collection_style' => $ctx['collection_style'],
                'collection_tag_primary' => $ctx['tag_primary'],
                'collection_tag_secondary' => $ctx['tag_secondary'],
                'active' => true,
                'sort_order' => 0,
            ]);
        }

        DropdownOption::query()
            ->where('header', $record->header)
            ->whereRaw('LOWER(value) = ?', [strtolower((string) $record->value)])
            ->where('active', false)
            ->delete();

        self::logChange($record, 'active', 'false', 'true', $userId);
        if ($recalculateErrors) {
            self::recalculateErrorsForOptionData($record->header, $record->value, null, null);
        }
    }

    public static function rejectForApplicableCollections(
        DropdownOption $record,
        ?int $userId = null,
        bool $recalculateErrors = true
    ): void
    {
        DropdownOption::query()
            ->where('header', $record->header)
            ->whereRaw('LOWER(value) = ?', [strtolower((string) $record->value)])
            ->where('active', false)
            ->delete();

        self::logChange($record, 'deleted', 'false', 'true', $userId);
        if ($recalculateErrors) {
            self::recalculateErrorsForOptionData($record->header, $record->value, null, null);
        }
    }

    /**
     * @return array<int, int>
     */
    public static function affectedProductIdsForHeaderValue(string $header, string $value): array
    {
        return self::affectedProductIds($header, $value, null, null);
    }

    /**
     * @param array<int, int> $productIds
     */
    public static function recalculateErrorsForProductIds(array $productIds): void
    {
        $productIds = array_values(array_unique(array_filter(array_map(
            fn (mixed $id): int => (int) $id,
            $productIds
        ), fn (int $id): bool => $id > 0)));

        if (empty($productIds)) {
            return;
        }

        $normalizer = app(Normalizer::class);
        Product::query()
            ->whereIn('id', $productIds)
            ->chunkById(200, function ($products) use ($normalizer): void {
                foreach ($products as $product) {
                    $normalizer->recalculateErrorsForProduct($product);
                }
            });
    }

    private static function resolvedCollectionStyle(DropdownOption $record): ?string
    {
        $current = trim((string) ($record->collection_style ?? ''));
        if ($current !== '' && self::isKnownCollectionStyle($current)) {
            return $current;
        }

        $primary = trim((string) ($record->collection_tag_primary ?? ''));
        if ($primary === '') {
            return null;
        }
        $secondary = trim((string) ($record->collection_tag_secondary ?? ''));
        $contexts = app(DropdownCollectionCatalog::class)->contexts();
        foreach ($contexts as $ctx) {
            if (strcasecmp((string) $ctx['tag_primary'], $primary) !== 0) {
                continue;
            }
            $ctxSecondary = (string) ($ctx['tag_secondary'] ?? '');
            if ($secondary !== '' && strcasecmp($ctxSecondary, $secondary) !== 0) {
                continue;
            }
            return (string) $ctx['collection_style'];
        }

        foreach ($contexts as $ctx) {
            if (strcasecmp((string) $ctx['tag_primary'], $primary) === 0) {
                return (string) $ctx['collection_style'];
            }
        }

        return null;
    }

    private static function collectionLabel(DropdownOption $record): string
    {
        $resolved = self::resolvedCollectionStyle($record);
        if ($resolved !== null) {
            return $resolved;
        }

        $primary = trim((string) ($record->collection_tag_primary ?? ''));
        $secondary = trim((string) ($record->collection_tag_secondary ?? ''));
        if ($primary !== '' && $secondary !== '') {
            return "{$primary} / {$secondary}";
        }
        if ($primary !== '') {
            return $primary;
        }

        return 'Unmapped collection';
    }

    private static function isKnownCollectionStyle(string $style): bool
    {
        $style = strtolower(trim($style));
        if ($style === '') {
            return false;
        }

        foreach (app(DropdownCollectionCatalog::class)->contexts() as $ctx) {
            if (strtolower((string) ($ctx['collection_style'] ?? '')) === $style) {
                return true;
            }
        }

        return false;
    }

    private static function cleanupUnknownPendingRows(): void
    {
        if (self::$cleanedUnknownPendingRows) {
            return;
        }
        self::$cleanedUnknownPendingRows = true;

        $allowed = [];
        foreach (app(DropdownCollectionCatalog::class)->contexts() as $ctx) {
            $allowed[strtolower(implode('|', [
                (string) ($ctx['collection_style'] ?? ''),
                (string) ($ctx['tag_primary'] ?? ''),
                (string) ($ctx['tag_secondary'] ?? ''),
            ]))] = true;
        }

        DropdownOption::query()
            ->where('active', false)
            ->chunkById(200, function ($rows) use ($allowed): void {
                foreach ($rows as $row) {
                    if (blank($row->collection_tag_primary) && blank($row->collection_tag_secondary)) {
                        // Keep unmapped pending rows visible so users can review/reject them.
                        continue;
                    }

                    $key = strtolower(implode('|', [
                        trim((string) ($row->collection_style ?? '')),
                        trim((string) ($row->collection_tag_primary ?? '')),
                        trim((string) ($row->collection_tag_secondary ?? '')),
                    ]));

                    if (!isset($allowed[$key])) {
                        // Preserve unknown mappings instead of silently deleting them.
                        // They still need review in the pending queue.
                        continue;
                    }
                }
            });
    }

    /**
     * @return array<int, array{collection_style:string,tag_primary:string,tag_secondary:?string}>
     */
    private static function applicableCollectionContexts(string $header): array
    {
        $contexts = app(DropdownCollectionCatalog::class)->contexts();

        $needle = match ($header) {
            HeaderStore::BRACELET_DESIGN => 'bracelet',
            'Necklace design (product.metafields.shopify.necklace-design)' => 'necklace',
            'Earring design (product.metafields.shopify.earring-design)' => 'earring',
            default => null,
        };

        if ($needle === null) {
            return $contexts;
        }

        return array_values(array_filter($contexts, function (array $ctx) use ($needle): bool {
            $haystack = strtolower(implode(' ', array_filter([
                $ctx['collection_style'] ?? '',
                $ctx['tag_primary'] ?? '',
                $ctx['tag_secondary'] ?? '',
            ])));

            return str_contains($haystack, $needle) || str_contains($haystack, $needle . 's');
        }));
    }

    private static function sendQueuedNotification(string $title, string $body): void
    {
        $notification = Notification::make()
            ->title($title)
            ->body($body)
            ->success();

        if ($user = Auth::user()) {
            $notification->sendToDatabase($user);
        }
        $notification->send();
    }
}
