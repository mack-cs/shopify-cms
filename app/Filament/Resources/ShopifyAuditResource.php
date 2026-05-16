<?php

namespace App\Filament\Resources;

use App\Filament\Resources\ShopifyAuditResource\Pages;
use App\Jobs\DailyComplementaryProductCheckJob;
use App\Models\Product;
use App\Models\ShopifyAudit;
use App\Services\NewProductDraftSeeder;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Auth;

class ShopifyAuditResource extends Resource
{
    protected static ?string $model = ShopifyAudit::class;
    protected static ?string $navigationGroup = 'Audit & History';
    protected static ?string $navigationLabel = 'Shopify Audit';
    protected static ?string $navigationIcon = 'heroicon-o-shield-check';
    protected static ?int $navigationSort = 9;

    public static function table(Table $table): Table
    {
        return $table
            ->defaultSort('last_checked_at', 'desc')
            ->columns([
                TextColumn::make('last_checked_at')
                    ->label('Last Checked')
                    ->dateTime()
                    ->sortable(),
                TextColumn::make('product.title')
                    ->label('Product')
                    ->searchable()
                    ->description(fn (ShopifyAudit $record): string => trim((string) ($record->product?->handle ?? ''))),
                TextColumn::make('product.status')
                    ->label('Status')
                    ->badge()
                    ->toggleable(),
                TextColumn::make('audit_type')
                    ->label('Audit')
                    ->formatStateUsing(fn (string $state): string => $state === ShopifyAudit::TYPE_COMPLEMENTARY_PRODUCTS ? 'Complementary Products' : $state)
                    ->badge()
                    ->color('warning'),
                TextColumn::make('local_saved_count')
                    ->label('Local Saved')
                    ->badge()
                    ->color(fn (ShopifyAudit $record): string => $record->local_saved_count >= 4 ? 'success' : 'warning')
                    ->sortable(),
                TextColumn::make('local_valid_count')
                    ->label('Local Valid')
                    ->description('Target: 4 saved with backups that are active and in stock')
                    ->sortable()
                    ->toggleable(),
                TextColumn::make('shopify_current_count')
                    ->label('Shopify Current')
                    ->badge()
                    ->color(fn (ShopifyAudit $record): string => $record->needs_attention ? 'danger' : 'success')
                    ->sortable(),
                TextColumn::make('shopify_valid_count')
                    ->label('Shopify Valid')
                    ->description('Healthy when Shopify refs stay valid and already exist in the local complementary list')
                    ->sortable()
                    ->toggleable(),
                TextColumn::make('status')
                    ->label('Shopify Health')
                    ->formatStateUsing(fn (string $state): string => $state === ShopifyAudit::STATUS_HEALTHY ? 'Healthy' : 'Needs Audit')
                    ->badge()
                    ->color(fn (string $state): string => $state === ShopifyAudit::STATUS_HEALTHY ? 'success' : 'danger'),
                TextColumn::make('issues')
                    ->label('Issues')
                    ->state(fn (ShopifyAudit $record): string => self::issuesSummary($record))
                    ->wrap()
                    ->toggleable(),
                TextColumn::make('last_notified_at')
                    ->label('Last Alerted')
                    ->dateTime()
                    ->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('product.last_synced_at')
                    ->label('Last Product Sync')
                    ->dateTime()
                    ->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('updated_at')
                    ->label('Audit Updated')
                    ->dateTime()
                    ->sortable()
                    ->toggleable(),
            ])
            ->filters([
                SelectFilter::make('status')
                    ->label('Shopify Health')
                    ->options([
                        ShopifyAudit::STATUS_HEALTHY => 'Healthy',
                        ShopifyAudit::STATUS_FLAGGED => 'Needs Audit',
                    ])
                    ->default(ShopifyAudit::STATUS_FLAGGED),
                SelectFilter::make('audit_type')
                    ->label('Audit Type')
                    ->options([
                        ShopifyAudit::TYPE_COMPLEMENTARY_PRODUCTS => 'Complementary Products',
                    ])
                    ->default(ShopifyAudit::TYPE_COMPLEMENTARY_PRODUCTS),
                SelectFilter::make('product_status')
                    ->label('Product Status')
                    ->options(fn (): array => Product::query()
                        ->whereNotNull('status')
                        ->where('status', '!=', '')
                        ->distinct()
                        ->orderBy('status')
                        ->pluck('status', 'status')
                        ->all())
                    ->query(function (Builder $query, array $data): Builder {
                        $value = trim((string) ($data['value'] ?? ''));
                        if ($value === '') {
                            return $query;
                        }

                        return $query->whereHas('product', fn (Builder $productQuery): Builder => $productQuery->where('status', $value));
                    }),
            ])
            ->actions([
                Tables\Actions\Action::make('editComplementary')
                    ->label('Edit Complementary')
                    ->icon('heroicon-o-pencil-square')
                    ->color('warning')
                    ->action(function (ShopifyAudit $record) {
                        $product = $record->product;
                        if (!$product instanceof Product) {
                            return null;
                        }

                        $draft = app(NewProductDraftSeeder::class)->upsertFromProduct($product, Auth::id());

                        return redirect(NewProductDraftResource::getUrl('edit', ['record' => $draft]));
                    }),
                Tables\Actions\Action::make('openProduct')
                    ->label('Open Product')
                    ->icon('heroicon-o-arrow-top-right-on-square')
                    ->url(fn (ShopifyAudit $record): ?string => $record->product
                        ? ProductResource::getUrl('edit', ['record' => $record->product])
                        : null)
                    ->openUrlInNewTab(),
            ])
            ->headerActions([
                Tables\Actions\Action::make('runComplementaryAuditNow')
                    ->label('Run Complementary Audit')
                    ->icon('heroicon-o-arrow-path')
                    ->color('primary')
                    ->requiresConfirmation()
                    ->action(function (): void {
                        DailyComplementaryProductCheckJob::dispatch();

                        Notification::make()
                            ->title('Complementary audit queued')
                            ->body('The live Shopify complementary audit is running in the background. Refresh this page after the worker finishes to see updated results.')
                            ->success()
                            ->send();
                    }),
            ])
            ->bulkActions([]);
    }

    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()
            ->with('product')

            ->where('audit_type', ShopifyAudit::TYPE_COMPLEMENTARY_PRODUCTS)
             ->whereHas('product',
             function (Builder $query): void {
            $query->where('status','=','active')->whereRaw('LOWER(title) NOT LIKE ?', ['%test%']);
        });;
    }

    public static function canViewAny(): bool
    {
        return Auth::check();
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

    public static function canDeleteAny(): bool
    {
        return false;
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListShopifyAudits::route('/'),
        ];
    }

    private static function issuesSummary(ShopifyAudit $record): string
    {
        $details = $record->details ?? [];
        $parts = [];

        foreach (($details['local_ineligible'] ?? []) as $item) {
            if (!is_array($item)) {
                continue;
            }

            $label = trim((string) ($item['title'] ?? '')) ?: trim((string) ($item['handle'] ?? ''));
            $reason = trim((string) ($item['reason'] ?? ''));
            if ($label !== '') {
                $parts[] = 'Local ref invalid on Shopify: ' . $label . ($reason !== '' ? ' (' . $reason . ')' : '');
            }
        }

        foreach (($details['shopify_ineligible'] ?? []) as $item) {
            if (!is_array($item)) {
                continue;
            }

            $label = trim((string) ($item['title'] ?? '')) ?: trim((string) ($item['handle'] ?? ''));
            $reason = trim((string) ($item['reason'] ?? ''));
            if ($label !== '') {
                $parts[] = 'Shopify ref invalid: ' . $label . ($reason !== '' ? ' (' . $reason . ')' : '');
            }
        }

        foreach (($details['shopify_missing_local'] ?? []) as $item) {
            if (!is_array($item)) {
                continue;
            }

            $label = trim((string) ($item['title'] ?? '')) ?: trim((string) ($item['handle'] ?? ''));
            if ($label !== '') {
                $parts[] = 'Shopify ref missing from local list: ' . $label;
            }
        }

        return $parts !== [] ? implode(' | ', $parts) : 'None';
    }
}
