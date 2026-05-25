<?php

namespace App\Filament\Resources\ProductResource\Widgets;

use App\Filament\Resources\ShopifyAuditResource;
use App\Models\ShopifyAudit;
use App\Services\ComplementaryProductAuditService;
use Filament\Widgets\Widget;

class ComplementaryShortageBanner extends Widget
{
    protected static string $view = 'filament.widgets.complementary-shortage-banner';

    protected int|string|array $columnSpan = 'full';

    protected function getViewData(): array
    {
        $query = ShopifyAudit::query()
            ->with('product:id,title,handle')
            ->where('audit_type', ShopifyAudit::TYPE_COMPLEMENTARY_PRODUCTS)
            ->where('needs_attention', true)
            ->where('local_valid_count', '<', ComplementaryProductAuditService::SHOPIFY_TARGET_COUNT);

        $count = (clone $query)->count();

        $records = $query
            ->orderByDesc('last_checked_at')
            ->limit(5)
            ->get();

        $items = $records->map(function (ShopifyAudit $audit): array {
            return [
                'title' => trim((string) ($audit->product?->title ?? '')) ?: ('Product #' . $audit->product_id),
                'handle' => trim((string) ($audit->product?->handle ?? '')),
                'local_valid_count' => (int) ($audit->local_valid_count ?? 0),
                'checked_at' => $audit->last_checked_at?->diffForHumans(),
            ];
        })->all();

        return [
            'count' => $count,
            'items' => $items,
            'auditUrl' => ShopifyAuditResource::getUrl('index'),
        ];
    }
}
