<?php

namespace App\Services;

use App\Jobs\ProductImageShopifySyncJob;
use App\Models\Approval;
use App\Models\Product;
use Illuminate\Support\Facades\DB;

class ProductImageApprovalWorkflowService
{
    public function __construct(
        private readonly ProductImageFilenameService $filenameService,
    ) {}

    public function handleApprovalCreated(Approval $approval): bool
    {
        $productId = (int) ($approval->product_id ?? 0);
        if ($productId <= 0) {
            return false;
        }

        $shouldQueue = false;
        $userId = (int) ($approval->user_id ?? 0) ?: null;

        DB::transaction(function () use ($productId, &$shouldQueue): void {
            $product = Product::query()
                ->lockForUpdate()
                ->find($productId);

            if (!$product) {
                return;
            }

            if ($product->first_image_auto_rename_completed_at !== null) {
                return;
            }

            if (!$product->isApprovedByTwo()) {
                return;
            }

            Product::withoutEvents(function () use ($product): void {
                $product->forceFill([
                    'first_image_auto_rename_completed_at' => now(),
                    'first_image_auto_rename_approval_version' => (int) ($product->approval_version ?? 1),
                ])->save();
            });

            $this->filenameService->assignFromCurrentTitle($product, manual: false);
            $shouldQueue = true;
        });

        if ($shouldQueue) {
            ProductImageShopifySyncJob::dispatch(
                [$productId],
                $userId,
                'First full approval image sync'
            );
        }

        return $shouldQueue;
    }
}
