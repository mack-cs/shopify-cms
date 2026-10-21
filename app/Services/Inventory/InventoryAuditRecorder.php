<?php

namespace App\Services\Inventory;

use App\Models\InventoryOperationAudit;
use Illuminate\Support\Carbon;
use Throwable;

final class InventoryAuditRecorder
{
    /**
     * @param array<string, mixed>|null $shopifyGroup
     */
    public function succeeded(InventoryAuditContext $context, ?array $shopifyGroup = null): void
    {
        $this->store($context, 'succeeded', null, $shopifyGroup);
    }

    public function failed(InventoryAuditContext $context, string $error): void
    {
        $this->store($context, 'failed', $error, null);
    }

    /**
     * @param array<string, mixed>|null $shopifyGroup
     */
    private function store(InventoryAuditContext $context, string $status, ?string $error, ?array $shopifyGroup): void
    {
        $key = trim($context->correlationKey);
        if ($key === '') {
            return;
        }

        try {
            $existing = InventoryOperationAudit::query()->where('correlation_key', $key)->first();
            if ($existing instanceof InventoryOperationAudit && $existing->status === 'succeeded') {
                $existing->forceFill([
                    'attempts' => (int) $existing->attempts + 1,
                ])->save();

                return;
            }

            $groupId = trim((string) (is_array($shopifyGroup) ? ($shopifyGroup['id'] ?? '') : ''));
            $row = $existing ?? new InventoryOperationAudit(['correlation_key' => $key]);
            $row->forceFill([
                'actor_user_id' => $context->userId,
                'actor_type' => $context->actorType,
                'execution' => $context->execution,
                'process_name' => $context->processName,
                'workflow' => $context->workflow,
                'action' => $context->action,
                'shopify_reason' => $context->shopifyReason,
                'explanation' => $context->explanation,
                'inventory_state' => $context->inventoryState,
                'delta' => $context->delta,
                'quantity_before' => $context->quantityBefore,
                'quantity_after' => $context->quantityAfter,
                'product_id' => $context->productId,
                'variant_id' => $context->variantId,
                'sku' => $context->sku,
                'shopify_inventory_item_id' => $context->inventoryItemId,
                'shopify_location_id' => $context->locationId,
                'reference_document_uri' => $context->referenceDocumentUri,
                'related_type' => $context->relatedType,
                'related_id' => $context->relatedId,
                'shopify_adjustment_group_id' => $groupId !== '' ? $groupId : $row->shopify_adjustment_group_id,
                'shopify_response' => $shopifyGroup ?? $row->shopify_response,
                'status' => $status,
                'attempts' => (int) ($row->attempts ?? 0) + 1,
                'error_message' => $status === 'failed' ? $error : null,
                'occurred_at' => $row->occurred_at ?? Carbon::now(),
            ])->save();
        } catch (Throwable $exception) {
            logger()->warning('Inventory operation audit could not be saved.', [
                'correlation_key' => $key,
                'status' => $status,
                'error' => $exception->getMessage(),
            ]);
        }
    }
}
