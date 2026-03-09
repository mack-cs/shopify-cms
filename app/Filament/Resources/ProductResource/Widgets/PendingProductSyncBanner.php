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
            ->whereNotNull('handle')
            ->where('handle', '!=', '')
            ->whereNotExists(function ($query): void {
                $query->selectRaw('1')
                    ->from('products')
                    ->whereColumn('products.handle', 'new_product_drafts.handle');
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
