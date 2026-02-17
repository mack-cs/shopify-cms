<?php

namespace App\Filament\Resources\ProductResource\Widgets;

use App\Filament\Resources\ImportResource;
use App\Models\NewProductDraft;
use Filament\Widgets\Widget;

class PendingProductSyncBanner extends Widget
{
    protected static string $view = 'filament.widgets.pending-product-sync-banner';

    protected int|string|array $columnSpan = 'full';

    protected function getViewData(): array
    {
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
            'syncUrl' => ImportResource::getUrl('index'),
        ];
    }
}
