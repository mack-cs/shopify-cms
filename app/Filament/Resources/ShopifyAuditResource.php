<?php

namespace App\Filament\Resources;

use App\Enums\RolesEnum;
use App\Filament\Resources\ShopifyAuditResource\Pages;
use App\Jobs\DailyComplementaryProductCheckJob;
use App\Jobs\ReconcileComplementaryProductsJob;
use App\Models\NewProductDraft;
use App\Models\Product;
use App\Models\ShopifyAudit;
use App\Services\AsyncJobStateService;
use App\Services\ComplementaryProductAuditService;
use App\Services\ComplementaryProductMaintenanceService;
use App\Services\NewProductDraftSeeder;
use App\Services\ProductShopifyUpdater;
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
                    ->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('audit_type')
                    ->label('Audit')
                    ->formatStateUsing(fn (string $state): string => $state === ShopifyAudit::TYPE_COMPLEMENTARY_PRODUCTS ? 'Complementary Products' : $state)
                    ->badge()
                    ->color('warning')
                    ->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('local_saved_count')
                    ->label('Local Saved')
                    ->badge()
                    ->color(fn (ShopifyAudit $record): string => $record->local_saved_count >= 4 ? 'success' : 'warning')
                    ->sortable()
                    ->toggleable(),
                TextColumn::make('local_valid_count')
                    ->label('Local Valid')
                    ->tooltip('Target: 4 saved with backups that are active and in stock')
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('shopify_current_count')
                    ->label('Shopify Current')
                    ->badge()
                    ->formatStateUsing(fn (?int $state): string => (string) ((int) ($state ?? 0)))
                    ->color(fn (ShopifyAudit $record): string => ((int) ($record->shopify_current_count ?? 0) >= ComplementaryProductAuditService::SHOPIFY_TARGET_COUNT) ? 'success' : 'danger')
                    ->sortable()
                    ->toggleable(),
                TextColumn::make('shopify_valid_count')
                    ->label('Shopify Valid')
                    ->tooltip('Healthy when Shopify refs stay valid and already exist in the local complementary list')
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('status')
                    ->label('Shopify Health')
                    ->formatStateUsing(fn (string $state): string => $state === ShopifyAudit::STATUS_HEALTHY ? 'Healthy' : 'Needs Audit')
                    ->badge()
                    ->color(fn (string $state): string => $state === ShopifyAudit::STATUS_HEALTHY ? 'success' : 'danger'),
                TextColumn::make('issues')
                    ->label('Issues')
                    ->state(fn (ShopifyAudit $record): string => self::issuesHtml($record))
                    ->html()
                    ->wrap()
                    ->toggleable()
                    ->extraAttributes([
                        'style' => 'min-width: 30rem; white-space: normal;',
                    ]),
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
                    ->action(fn (ShopifyAudit $record) => redirect(self::newProductDraftEditUrl($record))),
                Tables\Actions\Action::make('openProduct')
                    ->label('Open Draft')
                    ->icon('heroicon-o-arrow-top-right-on-square')
                    ->url(fn (ShopifyAudit $record): ?string => self::newProductDraftEditUrl($record))
                    ->openUrlInNewTab(),
                Tables\Actions\Action::make('resolveFromDraft')
                    ->label('Resolve')
                    ->icon('heroicon-o-wrench')
                    ->color('success')
                    ->visible(fn (ShopifyAudit $record): bool => self::canResolveFromDraft($record))
                    ->requiresConfirmation()
                    ->modalHeading('Resolve Shopify complementary shortage?')
                    ->modalDescription('This will push the valid complementary products from the linked draft to Shopify for this product only. Use this only when the local draft already has at least 3 valid complementary products.')
                    ->modalSubmitActionLabel('Resolve Now')
                    ->action(function (ShopifyAudit $record): void {
                        $product = $record->product;
                        if (!$product instanceof Product) {
                            Notification::make()
                                ->title('Resolve failed')
                                ->body('No linked product was found for this audit row.')
                                ->danger()
                                ->send();

                            return;
                        }

                        $draft = self::linkedDraftForProduct($product);
                        if (!$draft instanceof NewProductDraft) {
                            Notification::make()
                                ->title('Resolve failed')
                                ->body('No linked draft was found. Open the draft first and confirm the complementary products there.')
                                ->warning()
                                ->send();

                            return;
                        }

                        $auditService = app(ComplementaryProductAuditService::class);
                        $analysis = $auditService->analyzeDraft($draft);
                        $validCount = count($analysis['local_eligible_ids'] ?? []);

                        if ($validCount < ComplementaryProductAuditService::SHOPIFY_TARGET_COUNT) {
                            Notification::make()
                                ->title('Resolve blocked')
                                ->body("The linked draft only has {$validCount} valid complementary product(s). At least " . ComplementaryProductAuditService::SHOPIFY_TARGET_COUNT . ' valid products are required before this direct resolve can run.')
                                ->warning()
                                ->send();

                            return;
                        }

                        $result = app(ProductShopifyUpdater::class)->syncComplementaryProducts(collect([$product]), Auth::id());
                        app(ComplementaryProductMaintenanceService::class)->recordAuditForProduct($product->fresh() ?? $product);

                        if ((int) ($result['failed'] ?? 0) > 0) {
                            $details = collect($result['failures'] ?? [])
                                ->map(fn (array $failure): string => trim((string) ($failure['details'] ?? $failure['reason'] ?? '')))
                                ->filter()
                                ->implode(' | ');

                            Notification::make()
                                ->title('Resolve failed')
                                ->body($details !== '' ? $details : 'Shopify rejected the complementary update.')
                                ->danger()
                                ->send();

                            return;
                        }

                        if ((int) ($result['updated'] ?? 0) < 1) {
                            $warningText = collect($result['warnings'] ?? [])
                                ->map(fn (array $warning): string => trim((string) ($warning['warning'] ?? '')))
                                ->filter()
                                ->implode(' | ');

                            Notification::make()
                                ->title('Nothing changed')
                                ->body($warningText !== '' ? $warningText : 'No Shopify complementary update was applied for this product.')
                                ->warning()
                                ->send();

                            return;
                        }

                        Notification::make()
                            ->title('Complementary products resolved')
                            ->body('Shopify was updated from the linked draft for this product. Refresh the audit if you want to confirm the latest live result immediately.')
                            ->success()
                            ->send();
                    }),
            ])
            ->headerActions([
                Tables\Actions\Action::make('fixComplementaryProducts')
                    ->label('Fix Complementary Products')
                    ->icon('heroicon-o-wrench-screwdriver')
                    ->color('warning')
                    ->requiresConfirmation()
                    ->action(function (): void {
                        app(AsyncJobStateService::class)->markQueued(AsyncJobStateService::COMPLEMENTARY_RECONCILIATION);
                        ReconcileComplementaryProductsJob::dispatch(Auth::id());

                        Notification::make()
                            ->title('Complementary fix queued')
                            ->body('The complementary reconciliation job is running in the background. Refresh this page after the worker finishes to see updated audit results.')
                            ->success()
                            ->send();
                    }),
                Tables\Actions\Action::make('runComplementaryAuditNow')
                    ->label('Run Complementary Audit')
                    ->icon('heroicon-o-arrow-path')
                    ->color('primary')
                    ->requiresConfirmation()
                    ->action(function (): void {
                        app(AsyncJobStateService::class)->markQueued(AsyncJobStateService::COMPLEMENTARY_AUDIT);
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

    private static function issuesHtml(ShopifyAudit $record): string
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

        if ($parts === []) {
            return 'None';
        }

        $items = array_map(
            static fn (string $part): string => '<li style="margin-bottom: 0.35rem;">' . e($part) . '</li>',
            $parts
        );

        return '<ul style="margin:0; padding-left: 1rem; list-style: disc;">' . implode('', $items) . '</ul>';
    }

    private static function newProductDraftEditUrl(ShopifyAudit $record): ?string
    {
        $product = $record->product;
        if (!$product instanceof Product) {
            return null;
        }

        $draft = app(NewProductDraftSeeder::class)->upsertFromProduct($product, Auth::id());

        return NewProductDraftResource::getUrl('edit', ['record' => $draft]);
    }

    private static function canResolveFromDraft(ShopifyAudit $record): bool
    {
        return (Auth::user()?->hasRole(RolesEnum::SuperAdmin->value) ?? false)
            && $record->audit_type === ShopifyAudit::TYPE_COMPLEMENTARY_PRODUCTS
            && (int) ($record->local_valid_count ?? 0) >= ComplementaryProductAuditService::SHOPIFY_TARGET_COUNT
            && (int) ($record->shopify_current_count ?? 0) < ComplementaryProductAuditService::SHOPIFY_TARGET_COUNT;
    }

    private static function linkedDraftForProduct(Product $product): ?NewProductDraft
    {
        $shopifyId = trim((string) ($product->shopify_id ?? ''));
        if ($shopifyId !== '') {
            $draft = NewProductDraft::query()
                ->where('shopify_id', $shopifyId)
                ->first();

            if ($draft instanceof NewProductDraft) {
                return $draft;
            }
        }

        $handle = trim((string) ($product->handle ?? ''));
        if ($handle === '') {
            return null;
        }

        $draft = NewProductDraft::query()
            ->where('handle', $handle)
            ->first();

        return $draft instanceof NewProductDraft ? $draft : null;
    }
}
