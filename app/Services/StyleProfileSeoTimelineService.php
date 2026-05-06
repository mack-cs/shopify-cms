<?php

namespace App\Services;

use App\Models\Product;
use App\Models\StyleProfile;

class StyleProfileSeoTimelineService
{
    public const APPROVAL_SOURCE_FULL = 'full_product_approval';
    public const APPROVAL_SOURCE_PARTIAL = 'partial_seo_approval';

    public function markApprovedForSync(
        Product $product,
        ?int $userId = null,
        ?int $requestId = null,
        ?string $source = null
    ): void {
        $attributes = [
            'seo_approved_at' => now(),
            'seo_approved_by' => $userId,
            'seo_approval_source' => $source,
            'seo_approval_request_id' => $requestId,
            'seo_sync_status' => 'approved',
        ];

        $product->styleProfiles()->update($attributes);
    }

    public function markSynced(Product $product, ?int $userId = null, ?string $syncBatchId = null): void
    {
        $attributes = [
            'seo_sync_status' => 'synced',
            'seo_synced_at' => now(),
            'seo_synced_by' => $userId,
            'seo_sync_batch_id' => $syncBatchId,
        ];

        $product->styleProfiles()->update($attributes);
    }
}
