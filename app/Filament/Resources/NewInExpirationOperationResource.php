<?php

namespace App\Filament\Resources;

use App\Enums\RolesEnum;
use App\Filament\Resources\NewInExpirationOperationResource\Pages\ListNewInExpirationOperations;
use App\Models\NewProductDraft;
use App\Models\NewInExpirationOperation;
use App\Models\Product;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Filament\Resources\Resource;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Auth;

final class NewInExpirationOperationResource extends Resource
{
    protected static ?string $model = NewInExpirationOperation::class;

    protected static ?string $navigationGroup = 'Shopify Sync';

    protected static ?string $navigationLabel = 'New In Removals';

    protected static ?string $pluralModelLabel = 'New In Removals';

    protected static ?string $navigationIcon = 'heroicon-o-clock';

    protected static ?string $slug = 'new-in-removals';

    public static function canViewAny(): bool
    {
        return Auth::user()?->hasRole(RolesEnum::SuperAdmin->value) ?? false;
    }

    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()
            ->whereNotNull('remote_confirmed_at')
            ->addSelect(['catalogue_title' => Product::query()->select('title')
                ->whereColumn('products.shopify_id', 'new_in_expiration_operations.product_gid')
                ->latest('products.id')->limit(1),
                'draft_title' => NewProductDraft::query()->select('title')
                    ->whereColumn('new_product_drafts.shopify_id', 'new_in_expiration_operations.product_gid')
                    ->latest('new_product_drafts.id')->limit(1)]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->defaultSort('remote_confirmed_at', 'desc')
            ->recordUrl(null)
            ->columns([
                TextColumn::make('product')
                    ->label('Product')
                    ->state(fn (NewInExpirationOperation $record): string =>
                        data_get($record->meta, 'product_title') ?: ($record->catalogue_title ?: $record->draft_title ?: $record->product_gid))
                    ->description(fn (NewInExpirationOperation $record): string => $record->product_gid)
                    ->searchable(query: function (Builder $query, string $search): Builder {
                        return $query->where(function (Builder $query) use ($search): void {
                            $query->where('product_gid', 'like', "%{$search}%")
                                ->orWhere('meta->product_title', 'like', "%{$search}%")
                                ->orWhereIn('product_gid', Product::query()->select('shopify_id')
                                    ->where('title', 'like', "%{$search}%"))
                                ->orWhereIn('product_gid', NewProductDraft::query()->select('shopify_id')
                                    ->where('title', 'like', "%{$search}%"));
                        });
                    })
                    ->wrap(),
                TextColumn::make('product_skus')
                    ->label('SKUs at removal')
                    ->state(fn (NewInExpirationOperation $record): string => implode(', ', data_get($record->meta, 'product_skus', [])))
                    ->wrap()
                    ->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('product_status')
                    ->label('Shopify status at removal')
                    ->state(fn (NewInExpirationOperation $record): ?string => data_get($record->meta, 'product_status'))
                    ->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('shopify_created_at')
                    ->label('Created in Shopify')
                    ->dateTime('d M Y H:i:s T', 'Africa/Johannesburg')
                    ->sortable()
                    ->placeholder('Unavailable'),
                TextColumn::make('remote_confirmed_at')
                    ->label('Removal confirmed')
                    ->state(fn (NewInExpirationOperation $record): ?CarbonImmutable => self::operationTime($record, 'remote_confirmed_at'))
                    ->dateTime('d M Y H:i:s T', 'Africa/Johannesburg')
                    ->sortable(),
                TextColumn::make('age')
                    ->label('Age at confirmation')
                    ->state(fn (NewInExpirationOperation $record): ?string => self::ageAt($record, self::operationTime($record, 'remote_confirmed_at')))
                    ->description(fn (NewInExpirationOperation $record): ?string => filled(data_get($record->meta, 'expiration_days'))
                        ? data_get($record->meta, 'expiration_days').' day cutoff'
                        : null)
                    ->placeholder('Unavailable'),
                TextColumn::make('removed_tags')
                    ->label('New In tags removed')
                    ->state(function (NewInExpirationOperation $record): string {
                        $entries = data_get($record->meta, 'removal_confirmations', []);

                        return $entries === []
                            ? implode(', ', $record->tags_to_remove)
                            : implode(', ', array_unique(array_merge(...array_column($entries, 'tags'))));
                    })
                    ->wrap(),
                TextColumn::make('mutating_at')
                    ->label('Last removal attempt')
                    ->state(fn (NewInExpirationOperation $record): ?CarbonImmutable => self::operationTime($record, 'mutating_at'))
                    ->dateTime('d M Y H:i:s T', 'Africa/Johannesburg')
                    ->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('status')
                    ->label('CMS follow-up')
                    ->badge()
                    ->formatStateUsing(fn (string $state): string => match ($state) {
                        NewInExpirationOperation::STATUS_CONFIRMED => 'Complete',
                        NewInExpirationOperation::STATUS_REMOTE_CONFIRMED => 'Pending',
                        default => str($state)->replace('_', ' ')->title()->toString(),
                    })
                    ->color(fn (string $state): string => $state === NewInExpirationOperation::STATUS_CONFIRMED ? 'success' : 'warning')
                    ->description(fn (NewInExpirationOperation $record): ?string => $record->last_error),
                TextColumn::make('additional_removals')
                    ->label('Removal attempts confirmed by Shopify')
                    ->state(function (NewInExpirationOperation $record): ?string {
                        $entries = data_get($record->meta, 'removal_confirmations', []);
                        if ($entries === []) {
                            return null;
                        }

                        return collect($entries)->map(function (array $entry) use ($record): string {
                            $at = CarbonImmutable::parse($entry['confirmed_at'])->timezone('Africa/Johannesburg');

                            return implode(' · ', array_filter([
                                $at->format('d M Y H:i:s T'), self::ageAt($record, $at), implode(', ', $entry['tags']),
                            ]));
                        })->implode(' | ');
                    })
                    ->wrap()
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->emptyStateHeading('No confirmed New In removals')
            ->actions([])
            ->bulkActions([]);
    }

    private static function operationTime(NewInExpirationOperation $record, string $attribute): ?CarbonImmutable
    {
        $value = $record->getRawOriginal($attribute);

        return $value === null ? null : CarbonImmutable::parse($value, config('app.timezone'));
    }

    private static function ageAt(NewInExpirationOperation $record, ?CarbonInterface $at): ?string
    {
        return $record->shopify_created_at === null || $at === null
            ? null
            : (int) $record->shopify_created_at->diffInDays($at->copy()->utc()).' days';
    }

    public static function getPages(): array
    {
        return ['index' => ListNewInExpirationOperations::route('/')];
    }
}
