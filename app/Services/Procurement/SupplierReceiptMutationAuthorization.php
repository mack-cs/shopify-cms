<?php

namespace App\Services\Procurement;

use App\Models\ProcurementSupplierReceipt;
use App\Services\InventoryAccessService;
use App\Services\Shopify\ShopifyInventoryAdjustmentService;

/** Scoped capability for one persisted, explicitly approved receiving delta. Never a general bead-write switch. */
final class SupplierReceiptMutationAuthorization
{
    private ?array $capability = null;

    public function run(ProcurementSupplierReceipt $receipt, string $location, callable $send): mixed
    {
        $this->validate($receipt, $location);
        $eligibility = app(SupplierReceivingEligibility::class);
        $variant = $eligibility->variantForLine($receipt->line);
        if (! $eligibility->isBead($variant)) {
            return $send();
        }
        $approval = $receipt->receiving_authorization;
        $previous = $this->capability;
        $this->capability = ['item' => $variant->shopify_inventory_item_id, 'location' => $location, 'quantity' => $receipt->quantity_received, 'reference' => $receipt->shopify_reference_uri, 'receipt_id' => $receipt->id, 'actor_id' => (int) $approval['actor_id']];
        try {
            return $send();
        } finally {
            $this->capability = $previous;
        }
    }

    public function validate(ProcurementSupplierReceipt $receipt, string $location): void
    {
        $eligibility = app(SupplierReceivingEligibility::class);
        $variant = $eligibility->variantForLine($receipt->line);
        $approval = $receipt->receiving_authorization;
        if (is_array($approval)) {
            app(InventoryAccessService::class)->authorizeInventory((int) ($approval['actor_id'] ?? 0));
            abort_unless(($approval['variant_id'] ?? null) === $variant->shopify_id && ($approval['inventory_item_id'] ?? null) === $variant->shopify_inventory_item_id
                && ($approval['product_id'] ?? null) === $variant->product->shopify_id && ($approval['location_id'] ?? null) === $location
                && (int) ($approval['quantity'] ?? 0) === $receipt->quantity_received, 403, 'The receiving mapping changed after push approval. Review this receipt again.');
        }
        if (! $eligibility->isBead($variant)) {
            return;
        }
        abort_unless(is_array($approval) && isset($approval['actor_id']), 403, 'This tracked bead receipt needs explicit authorized push approval.');
        app(InventoryAccessService::class)->authorizeInventory((int) $approval['actor_id']);
        $source = $eligibility->approvedSource($variant);
        abort_unless($source && $source['source_location_id'] === $location
            && $approval['variant_id'] === $variant->shopify_id && $approval['inventory_item_id'] === $variant->shopify_inventory_item_id
            && $approval['product_id'] === $variant->product->shopify_id && $approval['location_id'] === $location
            && $approval['mapping_hash'] === $source['mapping_hash'] && $approval['configuration_version'] === $source['configuration_version']
            && (int) $approval['quantity'] === $receipt->quantity_received, 403, 'The receiving source or approval changed. Review this receipt again.');
    }

    public function permits(string $query, array $variables): bool
    {
        if (! $this->capability || preg_replace('/\s+/', ' ', trim($query)) !== preg_replace('/\s+/', ' ', trim(ShopifyInventoryAdjustmentService::SUPPLIER_RECEIPT_MUTATION))) {
            return false;
        }
        $c = $this->capability;
        $receipt = ProcurementSupplierReceipt::find($c['receipt_id']);
        if (! $receipt || $receipt->status !== 'processing' || ! $receipt->shopify_adjustment_started_at) {
            return false;
        }
        app(InventoryAccessService::class)->authorizeInventory($c['actor_id']);

        return $variables === ['input' => ['name' => 'available', 'reason' => 'received', 'referenceDocumentUri' => $c['reference'],
            'changes' => [['inventoryItemId' => $c['item'], 'locationId' => $c['location'], 'delta' => $c['quantity']]]],
            'idempotencyKey' => hash('sha256', 'inventory-adjust:'.$c['reference'])];
    }
}
