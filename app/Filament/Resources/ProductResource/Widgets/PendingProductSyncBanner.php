<?php

namespace App\Filament\Resources\ProductResource\Widgets;

use App\Filament\Resources\ImportResource;
use App\Models\Import;
use App\Models\NewProductDraft;
use Filament\Widgets\Widget;

class PendingProductSyncBanner extends Widget
{
    protected static string $view = 'filament.widgets.pending-product-sync-banner';
    protected static ?string $pollingInterval = '5s';

    protected int|string|array $columnSpan = 'full';

    protected function getViewData(): array
    {
        $currentImport = Import::query()
            ->where('is_current', true)
            ->orderByDesc('id')
            ->first();

        $isSyncing = strtolower(trim((string) ($currentImport?->status ?? ''))) === 'processing';

        $count = NewProductDraft::query()
            ->where('origin', NewProductDraft::ORIGIN_DRAFT_TOOL)
            ->where(function ($query): void {
                $query->whereNotNull('shopify_id')
                    ->where('shopify_id', '!=', '')
                    ->orWhere(function ($handleQuery): void {
                        $handleQuery->whereNotNull('handle')
                            ->where('handle', '!=', '');
                    });
            })
            ->whereNotExists(function ($query): void {
                $query->selectRaw('1')
                    ->from('products')
                    ->where(function ($matchQuery): void {
                        $matchQuery
                            ->where(function ($shopifyMatch): void {
                                $shopifyMatch
                                    ->whereColumn('products.shopify_id', 'new_product_drafts.shopify_id')
                                    ->whereNotNull('new_product_drafts.shopify_id')
                                    ->where('new_product_drafts.shopify_id', '!=', '');
                            })
                            ->orWhere(function ($handleMatch): void {
                                $handleMatch
                                    ->whereColumn('products.handle', 'new_product_drafts.handle')
                                    ->where(function ($draftWithoutId): void {
                                        $draftWithoutId
                                            ->whereNull('new_product_drafts.shopify_id')
                                            ->orWhere('new_product_drafts.shopify_id', '');
                                    });
                            });
                    });
            })
            ->count();

        return [
            'count' => $count,
            'isSyncing' => $isSyncing,
            'syncStartedAt' => $currentImport?->updated_at?->diffForHumans(),
            'syncUrl' => ImportResource::getUrl('index'),
        ];
    }
}
