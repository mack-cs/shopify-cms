<?php

namespace App\Services\EngravedBeads;

use App\Models\EngravedBeadOrderLine;
use App\Models\ShopifyStackInventoryMovement;
use App\Models\ShopifyStackInventoryReservation;
use App\Models\ShopifyStackOrderEvent;
use App\Models\Variant;
use App\Services\Shopify\StackInventoryMovementService;
use Closure;

/**
 * A multi-bead sale records one first bead and the remaining beads separately.
 * Shopify receives one adjustment for the total on the tracked 1-bead source.
 * A 1-bead sale is left to Shopify and is not reserved again.
 */
final class EngravedBeadOrderProcessor
{
    public function __construct(
        private readonly StackInventoryMovementService $movements,
        private readonly EngravedBeadStockPool $stockPool = new EngravedBeadStockPool,
    ) {}

    /**
     * @param  array<string, mixed>  $lineItem
     * @param  array{current:int, unfulfilled:?int}  $liveLine
     * @param  Closure(ShopifyStackOrderEvent, string, array, Variant, int, int, int, int, mixed): ShopifyStackInventoryReservation  $createReservation
     */
    public function prepareLine(
        ShopifyStackOrderEvent $event,
        string $lineId,
        array $lineItem,
        ?Variant $variant,
        array $liveLine,
        mixed $orderCreatedAt,
        Closure $createReservation,
    ): string {
        if (! $variant instanceof Variant || $variant->product === null) {
            return 'ignore';
        }

        $configuration = EngravedBeadConfiguration::fromProduct($variant->product);
        if (! $configuration->participating()) {
            return 'ignore';
        }

        $current = max(0, (int) ($liveLine['current'] ?? 0));
        $unfulfilled = max(0, (int) ($liveLine['unfulfilled'] ?? 0));
        $attributes = $this->baseAttributes($event, $lineId, $lineItem, $variant, $configuration, $current);

        if (! $configuration->valid()) {
            $this->remember($attributes, [
                'configuration' => EngravedBeadOrderLine::CONFIGURATION_INVALID,
                'error_message' => EngravedBeadConfiguration::INVALID_MESSAGE,
            ]);

            return 'recorded';
        }

        if ($configuration->additionalPerUnit() === 0) {
            $this->remember($attributes, [
                'configuration' => EngravedBeadOrderLine::CONFIGURATION_ZERO,
                'error_message' => null,
            ]);

            return 'recorded';
        }

        if ($unfulfilled === 0 && ! $this->reservation($event->shopify_order_id, $lineId) instanceof ShopifyStackInventoryReservation) {
            $this->remember($attributes, [
                'configuration' => EngravedBeadOrderLine::CONFIGURATION_HISTORICAL,
                'error_message' => 'Historical or already fulfilled quantity was not reserved and was not deducted.',
            ]);

            return 'recorded';
        }

        if (! $this->writesEnabled()) {
            $this->remember($attributes, [
                'configuration' => EngravedBeadOrderLine::CONFIGURATION_BLOCKED,
                'error_message' => 'Engraved bead inventory writes are disabled. No Shopify stock was changed.',
            ]);

            return 'recorded';
        }

        $member = $this->stockPool->member($variant);
        $source = $this->stockPool->source($variant);
        if ($member === null || $source === null || $variant->inventory_tracked !== false) {
            $this->remember($attributes, [
                'configuration' => EngravedBeadOrderLine::CONFIGURATION_BLOCKED,
                'stock_pool' => 'unresolved',
                'error_message' => 'The physical inventory item for this SKU could not be identified, so no additional beads were reserved.',
            ]);

            return 'recorded';
        }

        // Post-cutover shipments still owe CMS consumption even if their webhook is late.
        $fulfilledBaseline = 0;
        if ($orderCreatedAt === null || $orderCreatedAt->lt($member->family->activated_at)) {
            $this->remember($attributes, ['configuration' => EngravedBeadOrderLine::CONFIGURATION_HISTORICAL,
                'error_message' => 'Order predates the approved family cutover; no new inventory movement was created.']);

            return 'recorded';
        }
        $reservation = $createReservation(
            $event,
            $lineId,
            $lineItem + ['_bead_family_id' => $member->family_id,
                '_bead_configuration_version' => $member->family->configuration_version,
                '_bead_location_id' => $member->family->source_location_id],
            $variant,
            (int) $source->product_id,
            (int) $member->required_beads,
            $current,
            $fulfilledBaseline,
            $orderCreatedAt,
        );
        $reservation->forceFill([
            'bead_family_id' => $member->family_id,
            'bead_configuration_version' => $member->family->configuration_version,
            'shopify_location_id' => $member->family->source_location_id,
            'ledger_document_uri' => 'leighavenue-cms://engraved-bead-reservations/'.$reservation->id,
        ])->save();
        $this->remember($attributes, [
            'configuration' => EngravedBeadOrderLine::CONFIGURATION_VALID,
            'stock_pool' => 'family:'.$member->family_id,
            'shopify_inventory_item_id' => $source->shopify_inventory_item_id,
            'reservation_id' => $reservation->id,
            'error_message' => null,
        ]);

        return 'reservation_ready';
    }

    public function recordHistoricalSkip(ShopifyStackOrderEvent $event): void
    {
        if (! $this->enabled()) {
            return;
        }

        foreach ((array) data_get($event->payload, 'line_items', []) as $lineItem) {
            if (! is_array($lineItem)) {
                continue;
            }
            $lineId = $this->lineGid($lineItem);
            if ($lineId === '' || $this->reservation($event->shopify_order_id, $lineId) instanceof ShopifyStackInventoryReservation) {
                continue;
            }
            $variant = $this->variantFromLine($lineItem);
            $configuration = EngravedBeadConfiguration::fromProduct($variant?->product);
            if (! $configuration->participating()) {
                continue;
            }
            $quantity = max(0, (int) ($lineItem['current_quantity'] ?? $lineItem['quantity'] ?? 0));
            $this->remember(
                $this->baseAttributes($event, $lineId, $lineItem, $variant, $configuration, $quantity),
                [
                    'configuration' => $configuration->valid()
                        ? EngravedBeadOrderLine::CONFIGURATION_HISTORICAL
                        : EngravedBeadOrderLine::CONFIGURATION_INVALID,
                    'error_message' => $configuration->valid()
                        ? 'Fulfilled historical order did not create an active reservation.'
                        : EngravedBeadConfiguration::INVALID_MESSAGE,
                ],
            );
        }
    }

    public function reconcileRefunds(ShopifyStackOrderEvent $event): void
    {
        if (! $this->enabled() || ! $this->writesEnabled()) {
            return;
        }

        foreach ((array) data_get($event->payload, 'refunds', []) as $refund) {
            if (! is_array($refund)) {
                continue;
            }
            $refundId = trim((string) ($refund['admin_graphql_api_id'] ?? $refund['id'] ?? ''));
            if ($refundId === '') {
                continue;
            }
            foreach ((array) ($refund['refund_line_items'] ?? []) as $refundLine) {
                if (! is_array($refundLine)) {
                    continue;
                }
                $this->restockLine($event, $refundId, $refundLine);
            }
        }
    }

    /** @param array<string, mixed> $lineItem */
    public function fulfillmentMustWait(array $lineItem, string $orderId): bool
    {
        if (! $this->enabled() || ! $this->writesEnabled()) {
            return false;
        }
        $variant = $this->variantFromLine($lineItem);
        $configuration = EngravedBeadConfiguration::fromProduct($variant?->product);
        if (! $configuration->valid() || $configuration->additionalPerUnit() === 0 || ! $variant instanceof Variant) {
            return false;
        }
        if (! $this->stockPool->identifiable($variant)) {
            return false;
        }
        $lineId = $this->lineGid($lineItem);
        if ($lineId === '') {
            return false;
        }
        if ($this->reservation($orderId, $lineId) instanceof ShopifyStackInventoryReservation) {
            return false;
        }
        $state = EngravedBeadOrderLine::query()
            ->where('shopify_order_id', $orderId)
            ->where('shopify_order_line_item_id', $lineId)
            ->first();
        if ($state instanceof EngravedBeadOrderLine && in_array($state->configuration, [
            EngravedBeadOrderLine::CONFIGURATION_HISTORICAL,
            EngravedBeadOrderLine::CONFIGURATION_ZERO,
            EngravedBeadOrderLine::CONFIGURATION_INVALID,
            EngravedBeadOrderLine::CONFIGURATION_BLOCKED,
        ], true)) {
            return false;
        }

        return true;
    }

    /** @param array<string, mixed> $refundLine */
    private function restockLine(ShopifyStackOrderEvent $event, string $refundId, array $refundLine): void
    {
        $restockType = strtolower(trim((string) ($refundLine['restock_type'] ?? '')));
        if (! in_array($restockType, ['return', 'cancel', 'legacy_restock'], true)) {
            return;
        }
        $lineId = $this->lineGid([
            'admin_graphql_api_id' => $refundLine['line_item']['admin_graphql_api_id'] ?? null,
            'id' => $refundLine['line_item_id'] ?? $refundLine['line_item']['id'] ?? null,
        ]);
        if ($lineId === '') {
            return;
        }
        foreach (ShopifyStackInventoryReservation::query()->where('shopify_order_id', $event->shopify_order_id)
            ->where('shopify_order_line_item_id', $lineId)->whereNotNull('bead_family_id')->get() as $reservation) {
            $requested = max(0, (int) ($refundLine['quantity'] ?? 0)) * (int) $reservation->component_quantity_per_stack;
            $restockable = max(0, (int) $reservation->consumed_quantity - (int) $reservation->restocked_quantity);
            $quantity = min($requested, $restockable);
            if ($quantity <= 0) {
                continue;
            }
            $this->movements->execute($reservation, ShopifyStackInventoryMovement::ACTION_RESTOCK,
                $quantity, 'refund:'.$refundId.':'.$lineId);
        }
    }

    /**
     * @param  array<string, mixed>  $lineItem
     * @return array<string, mixed>
     */
    private function baseAttributes(
        ShopifyStackOrderEvent $event,
        string $lineId,
        array $lineItem,
        ?Variant $variant,
        EngravedBeadConfiguration $configuration,
        int $orderedQuantity,
    ): array {
        return [
            'shopify_order_id' => $event->shopify_order_id,
            'shopify_order_line_item_id' => $lineId,
            'product_id' => $variant?->product_id,
            'variant_id' => $variant?->id,
            'sku' => trim((string) ($variant?->sku ?: ($lineItem['sku'] ?? ''))) ?: null,
            'beads' => $configuration->beads(),
            'first_bead_per_unit' => ($configuration->beads() ?? 0) > 1 ? 1 : 0,
            'additional_per_unit' => $configuration->additionalPerUnit(),
            'ordered_quantity' => $orderedQuantity,
            'stock_pool' => null,
            'shopify_inventory_item_id' => $variant?->shopify_inventory_item_id,
        ];
    }

    /** @param array<string, mixed> $keys @param array<string, mixed> $values */
    private function remember(array $keys, array $values): void
    {
        EngravedBeadOrderLine::query()->updateOrCreate(
            [
                'shopify_order_id' => $keys['shopify_order_id'],
                'shopify_order_line_item_id' => $keys['shopify_order_line_item_id'],
            ],
            array_replace($keys, $values),
        );
    }

    private function reservation(string $orderId, string $lineId): ?ShopifyStackInventoryReservation
    {
        return ShopifyStackInventoryReservation::query()
            ->where('shopify_order_id', $orderId)
            ->where('shopify_order_line_item_id', $lineId)
            ->first();
    }

    /** @param array<string, mixed> $lineItem */
    private function variantFromLine(array $lineItem): ?Variant
    {
        $id = trim((string) ($lineItem['variant_id'] ?? ''));
        if ($id === '') {
            return null;
        }

        return Variant::query()->with('product.variants')->whereIn('shopify_id', [
            $id,
            str_starts_with($id, 'gid://shopify/') ? $id : 'gid://shopify/ProductVariant/'.$id,
        ])->first();
    }

    /** @param array<string, mixed> $lineItem */
    private function lineGid(array $lineItem): string
    {
        $id = trim((string) ($lineItem['admin_graphql_api_id'] ?? $lineItem['id'] ?? ''));
        if ($id === '') {
            return '';
        }

        return str_starts_with($id, 'gid://shopify/') ? $id : 'gid://shopify/LineItem/'.$id;
    }

    private function enabled(): bool
    {
        return (bool) config('engraved_beads.enabled', true);
    }

    private function writesEnabled(): bool
    {
        return $this->enabled()
            && (bool) config('engraved_beads.inventory_writes_enabled', false)
            && (bool) config('engraved_beads.store_contract_verified', false);
    }
}
