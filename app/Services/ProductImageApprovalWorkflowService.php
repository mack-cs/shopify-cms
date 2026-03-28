<?php

namespace App\Services;

use App\Models\Approval;
use App\Models\Product;
use Illuminate\Support\Facades\DB;

class ProductImageApprovalWorkflowService
{
    public function __construct(
        private readonly ProductImageFilenameService $filenameService,
        private readonly ProductHandleService $handleService,
    ) {}

    public function handleApprovalCreated(Approval $approval): bool
    {
        $productId = (int) ($approval->product_id ?? 0);
        if ($productId <= 0) {
            return false;
        }

        $updated = false;

        DB::transaction(function () use ($productId, &$updated): void {
            $product = Product::query()
                ->lockForUpdate()
                ->find($productId);

            if (!$product) {
                return;
            }

            if (
                $product->first_image_auto_rename_completed_at !== null
                && $product->hasLockedApprovedHandle()
            ) {
                return;
            }

            if (!$product->isApprovedByTwo()) {
                return;
            }

            if ($product->first_image_auto_rename_completed_at === null) {
                Product::withoutEvents(function () use ($product): void {
                    $product->forceFill([
                        'first_image_auto_rename_completed_at' => now(),
                        'first_image_auto_rename_approval_version' => (int) ($product->approval_version ?? 1),
                    ])->save();
                });

                $this->filenameService->assignFromCurrentTitle($product, manual: false);
                $updated = true;
            }

            if (!$product->hasLockedApprovedHandle()) {
                $this->handleService->lockInitialApprovedHandle($product->fresh());
                $updated = true;
            }
        });

        return $updated;
    }
}
