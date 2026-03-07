<?php

namespace App\Filament\Resources;

use App\Filament\Resources\PendingDropdownOptionResource\Pages;
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
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Auth;

class PendingDropdownOptionResource extends Resource
{
    protected static ?string $model = DropdownOption::class;
    protected static ?string $navigationIcon = 'heroicon-o-exclamation-triangle';
    protected static ?string $navigationGroup = 'Catalog';
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
                    ->options(fn () => DropdownOption::query()
                        ->whereNotNull('collection_style')
                        ->where('collection_style', '!=', '')
                        ->distinct()
                        ->orderBy('collection_style')
                        ->pluck('collection_style', 'collection_style')
                        ->all()),
            ])
            ->actions([
                Tables\Actions\Action::make('approve')
                    ->label('Approve')
                    ->icon('heroicon-o-check')
                    ->requiresConfirmation()
                    ->disabled(fn (DropdownOption $record): bool => blank(self::resolvedCollectionStyle($record)) || blank($record->collection_tag_primary))
                    ->action(function (DropdownOption $record): void {
                        self::approveForApplicableCollections($record);
                    }),
                Tables\Actions\Action::make('reject')
                    ->label('Reject')
                    ->icon('heroicon-o-trash')
                    ->color('danger')
                    ->requiresConfirmation()
                    ->action(function (DropdownOption $record): void {
                        self::rejectForApplicableCollections($record);
                    }),
            ])
            ->bulkActions([
                Tables\Actions\BulkAction::make('bulkApprove')
                    ->label('Bulk Approve')
                    ->icon('heroicon-o-check-badge')
                    ->requiresConfirmation()
                    ->action(function ($records): void {
                        $handled = [];

                        foreach ($records as $record) {
                            if ($record->active || blank(self::resolvedCollectionStyle($record)) || blank($record->collection_tag_primary)) {
                                continue;
                            }

                            $key = strtolower($record->header . '|' . $record->value);
                            if (isset($handled[$key])) {
                                continue;
                            }
                            $handled[$key] = true;

                            self::approveForApplicableCollections($record);
                        }
                    })
                    ->deselectRecordsAfterCompletion(),
                Tables\Actions\BulkAction::make('bulkReject')
                    ->label('Bulk Reject')
                    ->icon('heroicon-o-trash')
                    ->color('danger')
                    ->requiresConfirmation()
                    ->action(function ($records): void {
                        $handled = [];
                        foreach ($records as $record) {
                            $key = strtolower($record->header . '|' . $record->value);
                            if (isset($handled[$key])) {
                                continue;
                            }
                            $handled[$key] = true;
                            self::rejectForApplicableCollections($record);
                        }
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

    private static function logChange(DropdownOption $record, string $field, string $old, string $new): void
    {
        ChangeLog::create([
            'changed_by' => Auth::id(),
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

    private static function approveForApplicableCollections(DropdownOption $record): void
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

        self::logChange($record, 'active', 'false', 'true');
        self::recalculateAllProductErrors();
    }

    private static function rejectForApplicableCollections(DropdownOption $record): void
    {
        DropdownOption::query()
            ->where('header', $record->header)
            ->whereRaw('LOWER(value) = ?', [strtolower((string) $record->value)])
            ->where('active', false)
            ->delete();

        self::logChange($record, 'deleted', 'false', 'true');
        self::recalculateAllProductErrors();
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
                    $key = strtolower(implode('|', [
                        trim((string) ($row->collection_style ?? '')),
                        trim((string) ($row->collection_tag_primary ?? '')),
                        trim((string) ($row->collection_tag_secondary ?? '')),
                    ]));

                    if ($key === '||') {
                        $row->delete();
                        continue;
                    }

                    if (!isset($allowed[$key])) {
                        $row->delete();
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
}
