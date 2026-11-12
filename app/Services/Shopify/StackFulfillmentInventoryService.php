<?php

namespace App\Services\Shopify;

use App\Models\EngravedBeadFamily;
use App\Models\NewProductDraft;
use App\Models\ShopifyFulfillment;
use App\Models\ShopifyStackInventoryMovement;
use App\Models\ShopifyStackInventoryReservation;
use App\Models\Variant;
use App\Services\EngravedBeads\EngravedBeadOrderProcessor;
use Illuminate\Support\Facades\Cache;

final class StackFulfillmentInventoryService
{
    public function __construct(private readonly StackInventoryMovementService $movements) {}

    /** @return array{stack_lines:int,consumed:int,already_completed:int} */
    public function process(ShopifyFulfillment $fulfillment): array
    {
        if ($fulfillment->processing_status === ShopifyFulfillment::STATUS_COMPLETED) {
            return ['stack_lines' => 0, 'consumed' => 0, 'already_completed' => 1];
        }
        if (strtolower(trim((string) $fulfillment->shopify_status)) !== 'success') {
            $orderId = $this->gid('Order', $fulfillment->shopify_order_id);
            $families = EngravedBeadFamily::query()->whereIn('id',
                ShopifyStackInventoryReservation::query()->where('shopify_order_id', $orderId)
                    ->whereNotNull('bead_family_id')->where('consumed_quantity', '>', 0)->select('bead_family_id'))->get();
            foreach ($families as $family) {
                // Reversal is not proof that physical goods returned. Preserve consumption.
                $family->update(['status' => 'reconciliation_required', 'sync_error' => 'Fulfillment reversed after physical consumption; warehouse reconciliation required.']);
                $family->audits()->create(['action' => 'fulfillment_reversal', 'details' => [
                    'fulfillment_id' => $fulfillment->shopify_fulfillment_id, 'observed_status' => $fulfillment->shopify_status,
                    'physical_stock_restored' => false,
                ]]);
            }
            $fulfillment->forceFill([
                'processing_status' => ShopifyFulfillment::STATUS_IGNORED,
                'error_message' => 'Fulfillment status is not success; no reserved inventory was consumed.',
                'processed_at' => now(),
            ])->save();

            return ['stack_lines' => 0, 'consumed' => 0, 'already_completed' => 0];
        }

        $orderId = $this->gid('Order', $fulfillment->shopify_order_id);
        $lock = Cache::lock('stack-order-inventory:'.$orderId, 420);
        if (! $lock->get()) {
            throw new \RuntimeException('Stack order inventory is already being processed; retrying.');
        }

        try {
            return $this->processLocked($fulfillment, $orderId);
        } finally {
            $lock->release();
        }
    }

    /** @return array{stack_lines:int,consumed:int,already_completed:int} */
    private function processLocked(ShopifyFulfillment $fulfillment, string $orderId): array
    {
        if ((! config('engraved_beads.enabled', true) || ! config('engraved_beads.inventory_writes_enabled', false))
            && ShopifyStackInventoryReservation::query()->where('shopify_order_id', $orderId)
                ->where(fn ($q) => $q->whereNotNull('bead_family_id')->orWhere('ledger_document_uri', 'like', 'leighavenue-cms://engraved-bead-reservations/%'))->exists()) {
            $fulfillment->update(['processing_status' => ShopifyFulfillment::STATUS_PENDING,
                'error_message' => 'Deferred: engraved bead inventory writes are disabled.']);

            return ['stack_lines' => 0, 'consumed' => 0, 'already_completed' => 0];
        }
        $fulfillment->forceFill([
            'processing_status' => ShopifyFulfillment::STATUS_PROCESSING,
            'attempts' => (int) $fulfillment->attempts + 1,
            'processing_started_at' => now(),
            'error_message' => null,
        ])->save();
        $summary = ['stack_lines' => 0, 'consumed' => 0, 'already_completed' => 0];

        try {
            foreach ((array) data_get($fulfillment->payload, 'line_items', []) as $lineItem) {
                if (! is_array($lineItem) || (int) ($lineItem['quantity'] ?? 0) <= 0) {
                    continue;
                }
                $lineId = $this->gid('LineItem', $lineItem['admin_graphql_api_id'] ?? $lineItem['id'] ?? null);
                $reservations = ShopifyStackInventoryReservation::query()
                    ->where('shopify_order_id', $orderId)
                    ->where('shopify_order_line_item_id', $lineId)
                    ->get();
                $draft = NewProductDraft::configuredStackMatching($this->variantFromLine($lineItem), $lineItem);
                if ($reservations->isEmpty()) {
                    if ($draft instanceof NewProductDraft) {
                        throw new \RuntimeException("No component reservation exists for fulfilled Stack line {$lineId}; retrying after the order webhook.");
                    }
                    if ((new EngravedBeadOrderProcessor($this->movements))->fulfillmentMustWait($lineItem, $orderId)) {
                        throw new \RuntimeException("No engraved bead reservation exists for fulfilled line {$lineId}; retrying after the order webhook.");
                    }

                    continue;
                }
                if ($draft instanceof NewProductDraft) {
                    $present = [];
                    foreach ($reservations as $reservation) {
                        $present[(int) $reservation->configured_component_product_id] = true;
                    }
                    $missing = array_keys(array_filter(
                        $draft->configuredStackComponentQuantities(),
                        fn (int $quantity, int $productId): bool => ! isset($present[$productId]),
                        ARRAY_FILTER_USE_BOTH,
                    ));
                    if ($missing !== []) {
                        throw new \RuntimeException('Incomplete component reservation snapshot for fulfilled Stack line '.$lineId.'; missing component products '.implode(', ', $missing).'.');
                    }
                }

                $summary['stack_lines']++;
                foreach ($reservations as $reservation) {
                    if ($reservation->status === ShopifyStackInventoryReservation::STATUS_WRITTEN_OFF) {
                        $summary['already_completed']++;

                        continue;
                    }
                    if ($reservation->bead_family_id !== null) {
                        $family = EngravedBeadFamily::query()->findOrFail($reservation->bead_family_id);
                        if ($fulfillment->fulfilled_at_shopify === null) {
                            throw new \RuntimeException('Fulfillment timestamp is required to establish cutover provenance.');
                        }
                        if ($fulfillment->fulfilled_at_shopify->lt($family->activated_at)) {
                            $summary['already_completed']++;

                            continue;
                        }
                    }
                    if ((int) $reservation->historical_quantity > 0
                        && ($fulfillment->fulfilled_at_shopify === null
                            || $fulfillment->fulfilled_at_shopify->lte($reservation->created_at))) {
                        // No evidence that this fulfillment belongs to the CMS-controlled remainder.
                        $summary['already_completed']++;

                        continue;
                    }
                    $quantity = (int) $lineItem['quantity'] * (int) $reservation->component_quantity_per_stack;
                    if (str_starts_with((string) $reservation->ledger_document_uri, 'leighavenue-cms://engraved-bead-reservations/')) {
                        $quantity = min($quantity, $reservation->remainingReserved());
                        if ($quantity === 0) {
                            $summary['already_completed']++;

                            continue;
                        }
                    }
                    $source = $this->consumeSource($fulfillment, $lineId, $reservation);
                    if ($source === null) {
                        $summary['already_completed']++;

                        continue;
                    }
                    if ($quantity > $reservation->remainingReserved()) {
                        throw new \RuntimeException(
                            "Stack component {$reservation->component_sku} needs {$quantity} reserved units but only {$reservation->remainingReserved()} remain."
                        );
                    }
                    $this->movements->execute(
                        $reservation,
                        ShopifyStackInventoryMovement::ACTION_CONSUME,
                        $quantity,
                        $source,
                    );
                    $summary['consumed']++;
                }
            }
        } catch (\Throwable $exception) {
            $fulfillment->forceFill([
                'processing_status' => ShopifyFulfillment::STATUS_FAILED,
                'error_message' => $exception->getMessage(),
            ])->save();
            throw $exception;
        }

        $fulfillment->forceFill([
            'processing_status' => ShopifyFulfillment::STATUS_COMPLETED,
            'processed_at' => now(),
            'error_message' => null,
        ])->save();

        return $summary;
    }

    private function variantFromLine(array $lineItem): ?Variant
    {
        $variantId = trim((string) ($lineItem['variant_id'] ?? ''));
        if ($variantId === '') {
            return null;
        }

        return Variant::query()->with('product')->whereIn('shopify_id', [
            $variantId, $this->gid('ProductVariant', $variantId),
        ])->first();
    }

    private function consumeSource(ShopifyFulfillment $fulfillment, string $lineId, ShopifyStackInventoryReservation $reservation): ?string
    {
        $raw = trim((string) $fulfillment->shopify_fulfillment_id);
        $canonical = $this->gid('Fulfillment', $raw);
        $legacy = str_contains($canonical, '/') ? substr($canonical, strrpos($canonical, '/') + 1) : $raw;
        $sources = array_values(array_unique(array_filter([
            "fulfillment:{$canonical}:{$lineId}",
            $raw !== '' ? "fulfillment:{$raw}:{$lineId}" : '',
            $legacy !== '' ? "fulfillment:{$legacy}:{$lineId}" : '',
        ])));
        $keys = [];
        foreach ($sources as $source) {
            $keys[$source] = hash('sha256', implode('|', [
                $source, $reservation->id, ShopifyStackInventoryMovement::ACTION_CONSUME,
            ]));
        }
        $movements = $reservation->movements()->whereIn('event_key', array_values($keys))->get();
        if ($movements->contains(fn (ShopifyStackInventoryMovement $movement): bool => $movement->status === ShopifyStackInventoryMovement::STATUS_COMPLETED)) {
            return null;
        }
        $open = $movements->first();
        if ($open instanceof ShopifyStackInventoryMovement) {
            $source = array_search($open->event_key, $keys, true);

            return is_string($source) ? $source : $sources[0];
        }

        return "fulfillment:{$canonical}:{$lineId}";
    }

    private function gid(string $type, mixed $id): string
    {
        $id = trim((string) ($id ?? ''));
        if ($id === '') {
            return '';
        }

        return str_starts_with($id, 'gid://shopify/') ? $id : "gid://shopify/{$type}/{$id}";
    }
}
