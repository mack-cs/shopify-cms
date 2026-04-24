<?php

namespace App\Filament\Resources\NewProductDraftResource\Widgets;

use App\Models\NewProductDraft;
use Filament\Widgets\Widget;

class ShopifyMissingDraftBanner extends Widget
{
    protected static string $view = 'filament.widgets.shopify-missing-draft-banner';

    protected int|string|array $columnSpan = 'full';

    protected function getViewData(): array
    {
        $count = NewProductDraft::query()
            ->where('shopify_missing_sync_blocked', true)
            ->count();

        return [
            'count' => $count,
        ];
    }
}
