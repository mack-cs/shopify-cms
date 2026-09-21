<?php

namespace App\Services;

use App\Jobs\InventorySyncJob;
use App\Models\InventoryAdjustmentRequest;
use App\Models\Product;
use App\Models\ProductInventorySnapshot;
use App\Models\Variant;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

final class InventoryAdjustmentApprovalService
{
    public function __construct(private readonly ProductInventoryHistoryRecorder $historyRecorder) {}

    /**
     * @param array<int,array{variant:Variant,inventory_tracked:bool,on_hand_quantity:?int,reason:string}> $items
     */
    public function submit(array $items, int $requesterId, int $approverId, string $type = 'single', string $source = 'cms', ?string $filename = null): InventoryAdjustmentRequest
    {
        if ($requesterId === $approverId) {
            throw ValidationException::withMessages(['approver_id' => 'Requester cannot approve their own inventory adjustment.']);
        }
        if ($items === []) {
            throw ValidationException::withMessages(['items' => 'At least one inventory adjustment is required.']);
        }

        return DB::transaction(function () use ($items, $requesterId, $approverId, $type, $source, $filename): InventoryAdjustmentRequest {
            $request = InventoryAdjustmentRequest::query()->create([
                'uuid' => (string) Str::uuid(),
                'type' => $type,
                'status' => InventoryAdjustmentRequest::STATUS_PENDING_APPROVAL,
                'requester_id' => $requesterId,
                'approver_id' => $approverId,
                'submitted_at' => now(),
                'source' => $source,
                'original_filename' => $filename,
            ]);

            foreach ($items as $item) {
                $reason = trim((string) ($item['reason'] ?? ''));
                if ($reason === '') {
                    throw ValidationException::withMessages(['reason' => 'A reason is required for every inventory adjustment.']);
                }
                $variant = $item['variant'];
                if (! $variant instanceof Variant) {
                    throw ValidationException::withMessages(['items' => 'Inventory adjustment item is missing a variant.']);
                }

                $request->items()->create([
                    'variant_id' => $variant->id,
                    'sku' => $variant->sku,
                    'original_on_hand_quantity' => $variant->current_on_hand_quantity,
                    'requested_on_hand_quantity' => ($item['inventory_tracked'] ?? true) ? (int) ($item['on_hand_quantity'] ?? 0) : null,
                    'original_inventory_tracked' => $variant->inventory_tracked,
                    'requested_inventory_tracked' => (bool) ($item['inventory_tracked'] ?? false),
                    'reason' => $reason,
                ]);
            }

            return $request->load(['items.variant.product', 'requester', 'approver']);
        });
    }

    public function approve(InventoryAdjustmentRequest $request, int $reviewerId, bool $dispatchSync = true): InventoryAdjustmentRequest
    {
        if ((int) $request->requester_id === $reviewerId) {
            throw ValidationException::withMessages(['reviewer_id' => 'Requester cannot approve their own inventory adjustment.']);
        }
        if ((int) $request->approver_id !== $reviewerId) {
            throw ValidationException::withMessages(['reviewer_id' => 'Only the selected approver can approve this inventory adjustment.']);
        }
        if ($request->status !== InventoryAdjustmentRequest::STATUS_PENDING_APPROVAL) {
            return $request->fresh(['items']);
        }

        $syncBatchId = 'inventory_approval_'.$request->id.'_'.now()->format('YmdHis');
        $variantIds = [];
        $productIds = [];

        DB::transaction(function () use ($request, $reviewerId, $syncBatchId, &$variantIds, &$productIds): void {
            $locked = InventoryAdjustmentRequest::query()->whereKey($request->id)->lockForUpdate()->firstOrFail();
            if ($locked->status !== InventoryAdjustmentRequest::STATUS_PENDING_APPROVAL) {
                return;
            }

            $locked->load('items.variant');
            foreach ($locked->items as $item) {
                $variant = Variant::query()->lockForUpdate()->findOrFail($item->variant_id);
                InventoryOperationContext::run(function () use ($variant, $item): void {
                    $variant->inventory_tracked = (bool) $item->requested_inventory_tracked;
                    $variant->current_on_hand_quantity = $variant->inventory_tracked ? $item->requested_on_hand_quantity : null;
                    $variant->inventory_sync_error = null;
                    $variant->inventory_local_dirty = true;
                    $variant->save();
                });
                $variantIds[] = (int) $variant->id;
                $productIds[(int) $variant->product_id] = true;
            }

            $locked->update([
                'status' => InventoryAdjustmentRequest::STATUS_APPROVED,
                'reviewed_at' => now(),
                'reviewed_by' => $reviewerId,
                'sync_batch_id' => $syncBatchId,
            ]);
        });

        foreach (array_keys($productIds) as $productId) {
            $product = Product::query()->with('variants')->find((int) $productId);
            if ($product instanceof Product) {
                $this->historyRecorder->record($product, $reviewerId, ProductInventorySnapshot::SOURCE_LOCAL_UPDATE);
            }
        }

        if ($dispatchSync && $variantIds !== []) {
            InventorySyncJob::dispatch(array_values(array_unique($variantIds)), 'push', $reviewerId, $syncBatchId);
        }

        return $request->fresh(['items.variant', 'requester', 'approver']);
    }

    public function reject(InventoryAdjustmentRequest $request, int $reviewerId, ?string $reason = null): InventoryAdjustmentRequest
    {
        if ((int) $request->requester_id === $reviewerId) {
            throw ValidationException::withMessages(['reviewer_id' => 'Requester cannot reject their own inventory adjustment.']);
        }
        if ((int) $request->approver_id !== $reviewerId) {
            throw ValidationException::withMessages(['reviewer_id' => 'Only the selected approver can reject this inventory adjustment.']);
        }
        if ($request->status !== InventoryAdjustmentRequest::STATUS_PENDING_APPROVAL) {
            return $request->fresh(['items']);
        }

        $request->update([
            'status' => InventoryAdjustmentRequest::STATUS_REJECTED,
            'reviewed_at' => now(),
            'reviewed_by' => $reviewerId,
            'rejection_reason' => $reason,
        ]);

        return $request->fresh(['items.variant', 'requester', 'approver']);
    }

    public function markAppliedBySyncBatch(string $syncBatchId, string $result): void
    {
        $decoded = json_decode($result, true);
        $failed = is_array($decoded) && (int) ($decoded['failed'] ?? 0) > 0;

        InventoryAdjustmentRequest::query()
            ->where('sync_batch_id', $syncBatchId)
            ->where('status', InventoryAdjustmentRequest::STATUS_APPROVED)
            ->update([
                'status' => $failed ? InventoryAdjustmentRequest::STATUS_FAILED : InventoryAdjustmentRequest::STATUS_APPLIED,
                'applied_at' => $failed ? null : now(),
                'shopify_result' => $result,
            ]);
    }
}
