<?php

namespace App\Filament\Resources;

use App\Filament\Resources\PendingDropdownOptionResource\Pages;
use App\Models\ChangeLog;
use App\Models\DropdownOption;
use App\Models\Product;
use App\Models\ShopifyRow;
use App\Enums\RolesEnum;
use App\Services\Normalizer;
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

    public static function table(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn (Builder $query): Builder => $query
                ->where('active', false)
                ->orderBy('header')
                ->orderBy('value'))
            ->columns([
                TextColumn::make('header')->searchable()->wrap(),
                TextColumn::make('value')->searchable()->wrap(),
                TextColumn::make('collection_style')->label('Collection')->toggleable(isToggledHiddenByDefault: true),
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
                    ->action(function (DropdownOption $record): void {
                        if (!$record->active) {
                            $record->update(['active' => true]);
                            self::logChange($record, 'active', 'false', 'true');
                            self::recalculateErrorsForOption($record);
                        }
                    }),
                Tables\Actions\Action::make('reject')
                    ->label('Reject')
                    ->icon('heroicon-o-trash')
                    ->color('danger')
                    ->requiresConfirmation()
                    ->action(function (DropdownOption $record): void {
                        $header = $record->header;
                        $value = $record->value;
                        $tagPrimary = $record->collection_tag_primary;
                        $tagSecondary = $record->collection_tag_secondary;

                        self::logChange($record, 'deleted', 'false', 'true');
                        $record->delete();
                        self::recalculateErrorsForOptionData($header, $value, $tagPrimary, $tagSecondary);
                    }),
            ])
            ->bulkActions([
                Tables\Actions\BulkAction::make('bulkApprove')
                    ->label('Bulk Approve')
                    ->icon('heroicon-o-check-badge')
                    ->requiresConfirmation()
                    ->action(function ($records): void {
                        foreach ($records as $record) {
                            if ($record->active) {
                                continue;
                            }
                            $record->update(['active' => true]);
                            self::logChange($record, 'active', 'false', 'true');
                            self::recalculateErrorsForOption($record);
                        }
                    })
                    ->deselectRecordsAfterCompletion(),
                Tables\Actions\BulkAction::make('bulkReject')
                    ->label('Bulk Reject')
                    ->icon('heroicon-o-trash')
                    ->color('danger')
                    ->requiresConfirmation()
                    ->action(function ($records): void {
                        foreach ($records as $record) {
                            $header = $record->header;
                            $value = $record->value;
                            $tagPrimary = $record->collection_tag_primary;
                            $tagSecondary = $record->collection_tag_secondary;

                            self::logChange($record, 'deleted', 'false', 'true');
                            $record->delete();
                            self::recalculateErrorsForOptionData($header, $value, $tagPrimary, $tagSecondary);
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
            ->whereRaw("JSON_UNQUOTE(JSON_EXTRACT(shopify_rows.data, ?)) = ?", ['$.\"' . $header . '\"', $value]);

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
            ->whereRaw("JSON_UNQUOTE(JSON_EXTRACT(shopify_rows.data, ?)) = ?", ['$.\"' . $header . '\"', $value]);

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
}
