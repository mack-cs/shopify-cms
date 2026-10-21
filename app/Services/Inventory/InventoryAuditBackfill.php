<?php

namespace App\Services\Inventory;

use App\Models\InventoryAdjustmentRequest;
use App\Models\InventoryAdjustmentRequestItem;
use App\Models\InventoryOperationAudit;
use App\Models\ManualStockTransactionImpact;
use App\Models\ProcurementSupplierReceipt;
use App\Models\ShopifyStackInventoryMovement;
use Illuminate\Support\Carbon;

final class InventoryAuditBackfill
{
    /**
     * Copy existing CMS inventory records into the audit log.
     * This writes audit rows only. It does not call Shopify or change stock.
     *
     * @return array{inserted:int,skipped:int}
     */
    public function run(bool $dryRun = false): array
    {
        $counts = ['inserted' => 0, 'skipped' => 0];
        $this->receipts($dryRun, $counts);
        $this->stackMovements($dryRun, $counts);
        $this->manualAdjustments($dryRun, $counts);
        $this->corrections($dryRun, $counts);

        return $counts;
    }

    /**
     * @param array{inserted:int,skipped:int} $counts
     */
    private function receipts(bool $dryRun, array &$counts): void
    {
        ProcurementSupplierReceipt::query()
            ->with('line.variant')
            ->where('status', '!=', 'pending')
            ->where('status', '!=', 'processing')
            ->whereNotNull('shopify_reference_uri')
            ->orderBy('id')
            ->chunkById(200, function ($receipts) use ($dryRun, &$counts): void {
                foreach ($receipts as $receipt) {
                    $occurredAt = $receipt->shopify_adjusted_at ?? $receipt->created_at;
                    if (! $occurredAt) {
                        $counts['skipped']++;

                        continue;
                    }
                    $this->store($dryRun, $counts, [
                        'correlation_key' => (string) $receipt->shopify_reference_uri,
                        'actor_user_id' => $receipt->created_by ? (int) $receipt->created_by : null,
                        'actor_type' => $receipt->created_by ? 'user' : 'system',
                        'execution' => 'queue',
                        'process_name' => 'Supplier receipt job',
                        'workflow' => 'supplier_receipt',
                        'action' => 'inventory_received',
                        'shopify_reason' => 'received',
                        'explanation' => 'Supplier stock received',
                        'inventory_state' => 'available',
                        'delta' => (int) $receipt->quantity_received,
                        'product_id' => $receipt->line?->variant?->product_id,
                        'variant_id' => $receipt->line?->variant_id,
                        'sku' => $receipt->line?->sku,
                        'shopify_inventory_item_id' => $receipt->line?->variant?->shopify_inventory_item_id,
                        'reference_document_uri' => $receipt->shopify_reference_uri,
                        'related_type' => ProcurementSupplierReceipt::class,
                        'related_id' => (int) $receipt->id,
                        'status' => $receipt->status === 'succeeded' ? 'succeeded' : 'failed',
                        'error_message' => $receipt->status === 'succeeded' ? null : $receipt->error,
                        'occurred_at' => Carbon::parse($occurredAt),
                    ]);
                }
            });
    }

    /**
     * @param array{inserted:int,skipped:int} $counts
     */
    private function stackMovements(bool $dryRun, array &$counts): void
    {
        ShopifyStackInventoryMovement::query()
            ->with('reservation')
            ->whereNotIn('status', [ShopifyStackInventoryMovement::STATUS_PENDING, ShopifyStackInventoryMovement::STATUS_PROCESSING])
            ->whereNotNull('idempotency_key')
            ->orderBy('id')
            ->chunkById(200, function ($movements) use ($dryRun, &$counts): void {
                foreach ($movements as $movement) {
                    $reservation = $movement->reservation;
                    $occurredAt = $movement->processed_at ?? $movement->created_at;
                    if (! $occurredAt) {
                        $counts['skipped']++;

                        continue;
                    }
                    $action = (string) $movement->action;
                    $this->store($dryRun, $counts, [
                        'correlation_key' => (string) $movement->idempotency_key,
                        'actor_user_id' => null,
                        'actor_type' => 'system',
                        'execution' => 'queue',
                        'process_name' => 'Order processing',
                        'workflow' => match ($action) {
                            ShopifyStackInventoryMovement::ACTION_RELEASE => 'stack_release',
                            ShopifyStackInventoryMovement::ACTION_CONSUME => 'stack_consume',
                            default => 'stack_reserve',
                        },
                        'action' => $action !== '' ? $action : ShopifyStackInventoryMovement::ACTION_RESERVE,
                        'shopify_reason' => match ($action) {
                            ShopifyStackInventoryMovement::ACTION_RELEASE => 'reservation_deleted',
                            ShopifyStackInventoryMovement::ACTION_CONSUME => 'correction',
                            default => 'reservation_created',
                        },
                        'explanation' => match ($action) {
                            ShopifyStackInventoryMovement::ACTION_RELEASE => 'The stack reservation was released.',
                            ShopifyStackInventoryMovement::ACTION_CONSUME => 'Reserved component inventory was consumed when the stack was fulfilled.',
                            default => 'Component inventory was reserved because an order included this stack.',
                        },
                        'inventory_state' => 'reserved',
                        'delta' => $action === ShopifyStackInventoryMovement::ACTION_RESERVE
                            ? (int) $movement->quantity
                            : -1 * (int) $movement->quantity,
                        'product_id' => $reservation?->component_product_id,
                        'variant_id' => $reservation?->component_variant_id,
                        'sku' => $reservation?->component_sku,
                        'shopify_inventory_item_id' => $reservation?->shopify_inventory_item_id,
                        'shopify_location_id' => $reservation?->shopify_location_id,
                        'reference_document_uri' => 'gid://leigh-avenue-cms/StackInventoryMovement/'.$movement->id,
                        'related_type' => ShopifyStackInventoryMovement::class,
                        'related_id' => (int) $movement->id,
                        'status' => $movement->status === ShopifyStackInventoryMovement::STATUS_COMPLETED ? 'succeeded' : 'failed',
                        'error_message' => $movement->status === ShopifyStackInventoryMovement::STATUS_COMPLETED ? null : $movement->error_message,
                        'occurred_at' => Carbon::parse($occurredAt),
                    ]);
                }
            });
    }

    /**
     * @param array{inserted:int,skipped:int} $counts
     */
    private function manualAdjustments(bool $dryRun, array &$counts): void
    {
        ManualStockTransactionImpact::query()
            ->with(['transaction.processor', 'transaction.creator', 'variant'])
            ->whereNotIn('status', ['pending', 'processing'])
            ->whereNotNull('idempotency_key')
            ->orderBy('id')
            ->chunkById(200, function ($impacts) use ($dryRun, &$counts): void {
                foreach ($impacts as $impact) {
                    $transaction = $impact->transaction;
                    $user = $transaction?->processor ?: $transaction?->creator;
                    $occurredAt = $impact->processed_at ?? $impact->created_at;
                    if (! $occurredAt || ! $transaction) {
                        $counts['skipped']++;

                        continue;
                    }
                    $this->store($dryRun, $counts, [
                        'correlation_key' => (string) $impact->idempotency_key,
                        'actor_user_id' => $user?->id,
                        'actor_type' => $user ? 'user' : 'system',
                        'execution' => 'queue',
                        'process_name' => 'Manual stock job',
                        'workflow' => 'manual_stock',
                        'action' => 'manual_deduction',
                        'explanation' => $transaction->notes ?: ($transaction->transaction_type ?: 'Manual stock deduction'),
                        'inventory_state' => 'on_hand',
                        'delta' => -1 * (int) $impact->quantity_required,
                        'quantity_before' => $impact->on_hand_before !== null ? (int) $impact->on_hand_before : null,
                        'quantity_after' => $impact->on_hand_after !== null ? (int) $impact->on_hand_after : null,
                        'product_id' => $impact->variant?->product_id,
                        'variant_id' => $impact->variant_id,
                        'sku' => $impact->sku,
                        'shopify_inventory_item_id' => $impact->variant?->shopify_inventory_item_id,
                        'shopify_location_id' => $impact->shopify_location_id,
                        'reference_document_uri' => "gid://la-cms/ManualStockTransaction/{$transaction->id}/Impact/{$impact->id}",
                        'related_type' => ManualStockTransactionImpact::class,
                        'related_id' => (int) $impact->id,
                        'status' => match ((string) $impact->status) {
                            'completed' => 'succeeded',
                            'historical' => 'historical',
                            default => 'failed',
                        },
                        'error_message' => $impact->status === 'completed' || $impact->status === 'historical' ? null : $impact->error_message,
                        'occurred_at' => Carbon::parse($occurredAt),
                    ]);
                }
            });
    }

    /**
     * @param array{inserted:int,skipped:int} $counts
     */
    private function corrections(bool $dryRun, array &$counts): void
    {
        InventoryAdjustmentRequestItem::query()
            ->with(['request', 'variant'])
            ->whereHas('request', function ($query): void {
                $query->whereIn('status', [InventoryAdjustmentRequest::STATUS_APPLIED, InventoryAdjustmentRequest::STATUS_FAILED])
                    ->whereNotNull('sync_batch_id');
            })
            ->orderBy('id')
            ->chunkById(200, function ($items) use ($dryRun, &$counts): void {
                foreach ($items as $item) {
                    $request = $item->request;
                    $occurredAt = $request?->applied_at ?? $request?->updated_at;
                    $batch = trim((string) ($request?->sync_batch_id ?? ''));
                    if (! $request || ! $occurredAt || $batch === '' || ! $item->variant_id) {
                        $counts['skipped']++;

                        continue;
                    }
                    $before = $item->original_on_hand_quantity;
                    $after = $item->requested_on_hand_quantity;
                    $variantGid = trim((string) ($item->variant?->shopify_id ?? ''));
                    $this->store($dryRun, $counts, [
                        'correlation_key' => 'inventory-set:'.$batch.':'.$item->variant_id,
                        'actor_user_id' => $request->requester_id ? (int) $request->requester_id : null,
                        'actor_type' => $request->requester_id ? 'user' : 'system',
                        'execution' => 'queue',
                        'process_name' => 'Inventory sync job',
                        'workflow' => 'inventory_correction',
                        'action' => 'inventory_correction',
                        'shopify_reason' => 'correction',
                        'explanation' => $item->reason ?: 'Absolute on-hand quantity sent to Shopify.',
                        'inventory_state' => 'on_hand',
                        'delta' => $before !== null && $after !== null ? (int) $after - (int) $before : null,
                        'quantity_before' => $before !== null ? (int) $before : null,
                        'quantity_after' => $after !== null ? (int) $after : null,
                        'product_id' => $item->variant?->product_id,
                        'variant_id' => (int) $item->variant_id,
                        'sku' => $item->sku ?: $item->variant?->sku,
                        'shopify_inventory_item_id' => $item->variant?->shopify_inventory_item_id,
                        'reference_document_uri' => $variantGid !== ''
                            ? 'logistics://shopify-editor/inventory/'.rawurlencode($variantGid)
                            : null,
                        'related_type' => InventoryAdjustmentRequestItem::class,
                        'related_id' => (int) $item->id,
                        'status' => $request->status === InventoryAdjustmentRequest::STATUS_APPLIED ? 'succeeded' : 'failed',
                        'error_message' => $request->status === InventoryAdjustmentRequest::STATUS_APPLIED ? null : $request->shopify_result,
                        'occurred_at' => Carbon::parse($occurredAt),
                    ]);
                }
            });
    }

    /**
     * @param array{inserted:int,skipped:int} $counts
     * @param array<string, mixed> $attributes
     */
    private function store(bool $dryRun, array &$counts, array $attributes): void
    {
        $key = trim((string) $attributes['correlation_key']);
        if ($key === '' || InventoryOperationAudit::query()->where('correlation_key', $key)->exists()) {
            $counts['skipped']++;

            return;
        }
        $counts['inserted']++;
        if ($dryRun) {
            return;
        }

        InventoryOperationAudit::query()->create($attributes + [
            'attempts' => 1,
        ]);
    }
}
