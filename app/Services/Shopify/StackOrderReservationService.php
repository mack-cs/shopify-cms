<?php

namespace App\Services\Shopify;

use App\Models\EngravedBeadFamilyMember;
use App\Models\EngravedBeadOrderLine;
use App\Models\NewProductDraft;
use App\Models\Product;
use App\Models\ShopifyStackInventoryMovement;
use App\Models\ShopifyFulfillment;
use App\Models\ShopifyStackInventoryReservation;
use App\Models\ShopifyStackOrderEvent;
use App\Models\Variant;
use App\Services\EngravedBeads\EngravedBeadConfiguration;
use App\Services\EngravedBeads\EngravedBeadOrderProcessor;
use App\Services\EngravedBeads\EngravedBeadStockPool;
use Illuminate\Support\Facades\Cache;

final class StackOrderReservationService
{
    public function __construct(
        private readonly ShopifyInventoryAdjustmentService $inventory,
        private readonly StackInventoryMovementService $movements,
        private readonly StackOrderLineMapper $lines,
        private readonly StackOrderFulfillmentInspector $fulfillment,
        private ?EngravedBeadOrderProcessor $engravedBeads = null,
    ) {}

    /** @return array{stack_lines:int,reserved:int,released:int} */
    public function process(ShopifyStackOrderEvent $event): array
    {
        $lock = Cache::lock('stack-order-inventory:'.$event->shopify_order_id, 420);
        if (! $lock->get()) {
            throw new \RuntimeException('Stack order inventory is already being processed; retrying.');
        }

        try {
            return $this->processLocked($event);
        } finally {
            $lock->release();
        }
    }

    /** @return array{stack_lines:int,reserved:int,released:int} */
    private function processLocked(ShopifyStackOrderEvent $event): array
    {
        if ($event->status === ShopifyStackOrderEvent::STATUS_COMPLETED) {
            $deferred = EngravedBeadOrderLine::query()->where('shopify_order_id', $event->shopify_order_id)
                ->where('configuration', EngravedBeadOrderLine::CONFIGURATION_BLOCKED)->exists();
            if (! $deferred || ! config('engraved_beads.inventory_writes_enabled', false)
                || ! config('engraved_beads.store_contract_verified', false)) {
                return ['stack_lines' => 0, 'reserved' => 0, 'released' => 0];
            }
        }
        if ($event->shopify_updated_at !== null && ShopifyStackOrderEvent::query()
            ->where('shopify_order_id', $event->shopify_order_id)
            ->where('id', '!=', $event->id)
            ->where('status', ShopifyStackOrderEvent::STATUS_COMPLETED)
            ->where('shopify_updated_at', '>', $event->shopify_updated_at)
            ->exists()) {
            $event->forceFill([
                'status' => ShopifyStackOrderEvent::STATUS_COMPLETED,
                'processed_at' => now(),
                'error_message' => 'Ignored because a newer Shopify order event was already processed.',
            ])->save();

            return ['stack_lines' => 0, 'reserved' => 0, 'released' => 0];
        }

        $event->forceFill([
            'status' => ShopifyStackOrderEvent::STATUS_PROCESSING,
            'attempts' => (int) $event->attempts + 1,
            'error_message' => null,
        ])->save();

        $summary = ['stack_lines' => 0, 'reserved' => 0, 'released' => 0];
        $skipNote = null;
        try {
            $live = $this->fulfillment->liveOrder($event->shopify_order_id);
            if (! $live['known']) {
                throw new \RuntimeException('Live Shopify order status could not be confirmed; no component stock was reserved.');
            }
            if ($live['fulfilled'] && ! $live['cancelled'] && $event->topic !== 'orders/cancelled') {
                $skipNote = 'Skipped reservation because live Shopify shows this order as fulfilled.';
                $owesOpenFulfillment = $this->owesOpenFulfillment($event->shopify_order_id);
                $this->beads()->recordHistoricalSkip($event);
                foreach ((array) data_get($event->payload, 'line_items', []) as $lineItem) {
                    if (! is_array($lineItem)) {
                        continue;
                    }
                    $variant = $this->findVariant($lineItem['variant_id'] ?? null);
                    $member = $variant ? app(EngravedBeadStockPool::class)->member($variant) : null;
                    $created = $this->lines->orderCreatedAt((array) $event->payload);
                    $draft = $this->findStackDraft($variant, $lineItem);
                    $beadStack = false;
                    if ($draft && $created !== null) {
                        $componentIds = array_keys($this->componentConfiguration($draft));
                        $beadStack = EngravedBeadFamilyMember::query()
                            ->whereIn('shopify_product_id', Product::query()->whereIn('id', $componentIds)->select('shopify_id'))
                            ->whereHas('family', fn ($q) => $q->where('status', 'active')->where('activated_at', '<=', $created))->exists();
                    }
                    if ($draft instanceof NewProductDraft && $owesOpenFulfillment && ! $beadStack) {
                        $lineId = $this->gid('LineItem', $lineItem['admin_graphql_api_id'] ?? $lineItem['id'] ?? null);
                        if (! isset($live['lines'][$lineId])) {
                            continue;
                        }
                        $summary = $this->reconcileLine($event, $lineItem, $summary, $live, true);
                        $skipNote = null;

                        continue;
                    }
                    if (! $beadStack && ($member === null || $member->required_beads === 1 || $created === null
                        || $created->lt($member->family->activated_at))) {
                        continue;
                    }
                    // A post-cutover fast fulfillment still owes the full physical demand.
                    $lineId = $this->gid('LineItem', $lineItem['admin_graphql_api_id'] ?? $lineItem['id'] ?? null);
                    if (! isset($live['lines'][$lineId])) {
                        continue;
                    }
                    $fast = $live;
                    $fast['lines'][$lineId]['unfulfilled'] = $fast['lines'][$lineId]['current'];
                    $summary = $this->reconcileLine($event, $lineItem, $summary, $fast);
                    $skipNote = null;
                }
            } elseif ($event->topic === 'orders/cancelled' || $live['cancelled']) {
                $this->beads()->recordHistoricalSkip($event);
                $summary = $this->releaseAll($event->shopify_order_id, 'cancel:'.$event->shopify_order_id, $summary);
            } else {
                $owesOpenFulfillment = $this->owesOpenFulfillment($event->shopify_order_id);
                foreach ((array) data_get($event->payload, 'line_items', []) as $lineItem) {
                    if (! is_array($lineItem)) {
                        continue;
                    }
                    $summary = $this->reconcileLine($event, $lineItem, $summary, $live, $owesOpenFulfillment);
                }
                // Removed lines can disappear from webhook payloads. Reconcile known snapshots too.
                $present = collect((array) data_get($event->payload, 'line_items', []))->map(fn ($line) => $this->gid('LineItem', $line['admin_graphql_api_id'] ?? $line['id'] ?? null))->all();
                foreach (ShopifyStackInventoryReservation::query()->where('shopify_order_id', $event->shopify_order_id)
                    ->whereNotNull('bead_family_id')->whereNotIn('shopify_order_line_item_id', $present)->get() as $missing) {
                    $lineId = $missing->shopify_order_line_item_id;
                    $snapshotLive = $live;
                    $snapshotLive['lines'][$lineId] ??= ['current' => 0, 'unfulfilled' => 0];
                    $summary = $this->reconcileLine($event, ['admin_graphql_api_id' => $lineId,
                        'variant_id' => $missing->shopify_stack_variant_id], $summary, $snapshotLive);
                }
            }
            $this->beads()->reconcileRefunds($event);
        } catch (\Throwable $exception) {
            $event->forceFill([
                'status' => ShopifyStackOrderEvent::STATUS_FAILED,
                'error_message' => $exception->getMessage(),
            ])->save();
            throw $exception;
        }

        $event->forceFill([
            'status' => ShopifyStackOrderEvent::STATUS_COMPLETED,
            'processed_at' => now(),
            'error_message' => $skipNote,
        ])->save();

        return $summary;
    }

    /**
     * @param  iterable<int, ShopifyStackInventoryReservation|int>  $reservations
     * @return array{cancelled:int,skipped:int,failed:int,errors:array<int,string>}
     */
    public function cancelManually(iterable $reservations): array
    {
        $summary = ['cancelled' => 0, 'skipped' => 0, 'failed' => 0, 'errors' => []];
        foreach ($reservations as $reservation) {
            $record = $reservation instanceof ShopifyStackInventoryReservation
                ? $reservation
                : ShopifyStackInventoryReservation::query()->find($reservation);
            if (! $record instanceof ShopifyStackInventoryReservation) {
                $summary['skipped']++;

                continue;
            }
            try {
                if ($this->cancelOne($record)) {
                    $summary['cancelled']++;
                } else {
                    $summary['skipped']++;
                }
            } catch (\Throwable $exception) {
                $summary['failed']++;
                $summary['errors'][] = trim((string) ($record->shopify_order_name ?: $record->shopify_order_id))
                    .' '.$record->component_sku.': '.$exception->getMessage();
            }
        }

        return $summary;
    }

    /** @return array{deleted:int,skipped:int,failed:int,errors:array<int, string>} */
    public function deleteHeldStock(iterable $reservations, ?int $actorUserId = null): array
    {
        $summary = ['deleted' => 0, 'skipped' => 0, 'failed' => 0, 'errors' => []];
        foreach ($reservations as $reservation) {
            $record = $reservation instanceof ShopifyStackInventoryReservation
                ? $reservation
                : ShopifyStackInventoryReservation::query()->find($reservation);
            if (! $record instanceof ShopifyStackInventoryReservation) {
                $summary['skipped']++;

                continue;
            }
            try {
                if ($this->deleteOne($record, $actorUserId)) {
                    $summary['deleted']++;
                } else {
                    $summary['skipped']++;
                }
            } catch (\Throwable $exception) {
                $summary['failed']++;
                $summary['errors'][] = trim((string) ($record->shopify_order_name ?: $record->shopify_order_id))
                    .' '.$record->component_sku.': '.$exception->getMessage();
            }
        }

        return $summary;
    }

    private function cancelOne(ShopifyStackInventoryReservation $reservation): bool
    {
        $lock = Cache::lock('stack-order-inventory:'.$reservation->shopify_order_id, 420);
        if (! $lock->get()) {
            throw new \RuntimeException('Stack order inventory is already being processed; retrying.');
        }

        try {
            $locked = ShopifyStackInventoryReservation::query()->findOrFail($reservation->id);
            $this->guardManualClose($locked);
            if ($locked->status === ShopifyStackInventoryReservation::STATUS_RELEASED
                && $locked->remainingReserved() <= 0) {
                return false;
            }
            if ($locked->status === ShopifyStackInventoryReservation::STATUS_COMPLETED
                && $locked->remainingReserved() <= 0) {
                return false;
            }
            if ($locked->status === ShopifyStackInventoryReservation::STATUS_WRITTEN_OFF
                && $locked->remainingReserved() <= 0) {
                return false;
            }
            if ($locked->remainingReserved() <= 0) {
                $locked->forceFill([
                    'status' => ShopifyStackInventoryReservation::STATUS_RELEASED,
                    'released_at' => $locked->released_at ?? now(),
                    'error_message' => 'Manually cancelled by a super admin.',
                ])->save();

                return true;
            }

            $locked->loadMissing('componentVariant');
            $variant = $locked->componentVariant;
            if (! $variant instanceof Variant) {
                throw new \RuntimeException('The snapshotted component variant no longer exists.');
            }
            $remaining = $locked->remainingReserved();
            $held = $this->inventory->reservedQuantity($variant, $locked->shopify_location_id);
            $move = min($remaining, max(0, $held));
            if ($move > 0) {
                $this->movements->execute(
                    $locked,
                    ShopifyStackInventoryMovement::ACTION_RELEASE,
                    $move,
                    'manual-cancel:'.$locked->id,
                );
            }
            $locked = $locked->fresh() ?? $locked;
            $unmoved = $locked->remainingReserved();
            if ($unmoved > 0) {
                $locked->released_quantity = (int) $locked->released_quantity + $unmoved;
            }
            $locked->released_at = $locked->released_at ?? now();
            $locked->refreshLedgerStatus();
            if ($locked->remainingReserved() <= 0) {
                $locked->status = ShopifyStackInventoryReservation::STATUS_RELEASED;
            }
            $locked->error_message = $unmoved > 0
                ? 'Manually closed. Live Shopify reserved stock did not cover this reservation, so no extra quantity was added.'
                : 'Manually cancelled by a super admin.';
            $locked->save();

            return true;
        } finally {
            $lock->release();
        }
    }

    private function deleteOne(ShopifyStackInventoryReservation $reservation, ?int $actorUserId): bool
    {
        $lock = Cache::lock('stack-order-inventory:'.$reservation->shopify_order_id, 420);
        if (! $lock->get()) {
            throw new \RuntimeException('Stack order inventory is already being processed; retrying.');
        }

        try {
            $locked = ShopifyStackInventoryReservation::query()->findOrFail($reservation->id);
            $this->guardManualClose($locked);
            if (in_array($locked->status, [
                ShopifyStackInventoryReservation::STATUS_RELEASED,
                ShopifyStackInventoryReservation::STATUS_COMPLETED,
                ShopifyStackInventoryReservation::STATUS_WRITTEN_OFF,
            ], true) && $locked->remainingReserved() <= 0) {
                return false;
            }
            if ($locked->remainingReserved() <= 0) {
                $locked->forceFill([
                    'status' => ShopifyStackInventoryReservation::STATUS_WRITTEN_OFF,
                    'error_message' => 'Reservation deleted. No reserved units were left to remove.',
                ])->save();

                return true;
            }

            $locked->loadMissing('componentVariant');
            $variant = $locked->componentVariant;
            if (! $variant instanceof Variant) {
                throw new \RuntimeException('The snapshotted component variant no longer exists.');
            }
            $remaining = $locked->remainingReserved();
            $held = $this->inventory->reservedQuantity($variant, $locked->shopify_location_id);
            $remove = min($remaining, max(0, $held));
            if ($remove > 0) {
                $this->movements->execute(
                    $locked,
                    ShopifyStackInventoryMovement::ACTION_WRITE_OFF,
                    $remove,
                    'manual-delete:'.$locked->id,
                    $actorUserId,
                );
            }
            $locked = $locked->fresh() ?? $locked;
            $unmoved = $locked->remainingReserved();
            if ($unmoved > 0) {
                $locked->written_off_quantity = (int) $locked->written_off_quantity + $unmoved;
            }
            $locked->refreshLedgerStatus();
            if ($locked->remainingReserved() <= 0) {
                $locked->status = ShopifyStackInventoryReservation::STATUS_WRITTEN_OFF;
            }
            $locked->error_message = $remove === 0
                ? 'Reservation deleted. Live Shopify reserved stock did not cover this reservation, so no quantity was removed and none was returned to available.'
                : ($unmoved > 0
                    ? "Reservation deleted. {$remove} reserved unit(s) were removed from stock. {$unmoved} unit(s) were no longer held in Shopify, so they were closed here without returning quantity to available."
                    : "Reservation deleted. {$remove} reserved unit(s) were removed from stock and were not returned to available.");
            $locked->save();

            return true;
        } finally {
            $lock->release();
        }
    }

    /**
     * @param  array{lines:array<string, array{current:int, unfulfilled:?int}>}  $live
     * @param  array{stack_lines:int,reserved:int,released:int}  $summary
     * @return array{stack_lines:int,reserved:int,released:int}
     */
    private function reconcileLine(ShopifyStackOrderEvent $event, array $lineItem, array $summary, array $live, bool $owesOpenFulfillment = false): array
    {
        $lineId = $this->gid('LineItem', $lineItem['admin_graphql_api_id'] ?? $lineItem['id'] ?? null);
        if ($lineId === '') {
            return $summary;
        }

        $reservations = ShopifyStackInventoryReservation::query()
            ->where('shopify_order_id', $event->shopify_order_id)
            ->where('shopify_order_line_item_id', $lineId)
            ->get();
        $liveLine = $live['lines'][$lineId] ?? null;
        if (! is_array($liveLine) || $liveLine['unfulfilled'] === null) {
            return $summary;
        }

        $stackVariant = $this->findVariant($lineItem['variant_id'] ?? null);
        $draft = $this->findStackDraft($stackVariant, $lineItem);
        if ($reservations->isEmpty() && ! $draft instanceof NewProductDraft) {
            $outcome = $this->beads()->prepareLine(
                $event,
                $lineId,
                $lineItem,
                $stackVariant,
                $liveLine,
                $this->lines->orderCreatedAt((array) $event->payload),
                fn (...$arguments) => $this->createReservation(...$arguments),
            );
            if ($outcome !== 'reservation_ready') {
                return $summary;
            }
            $reservations = ShopifyStackInventoryReservation::query()
                ->where('shopify_order_id', $event->shopify_order_id)
                ->where('shopify_order_line_item_id', $lineId)
                ->get();
        }
        if ($reservations->isEmpty() && ! $draft instanceof NewProductDraft) {
            return $summary;
        }

        $currentStack = max(0, (int) $liveLine['current']);
        $unfulfilledStack = max(0, min($currentStack, (int) $liveLine['unfulfilled']));
        $missingComponents = $draft instanceof NewProductDraft
            ? $this->missingComponents($reservations, $draft)
            : [];
        if ($missingComponents !== [] && ! $stackVariant instanceof Variant) {
            $sku = trim((string) ($lineItem['sku'] ?? ''));
            throw new \RuntimeException('Configured stack '.($sku !== '' ? $sku : $lineId).' is not linked to a local variant, so its component reservations were not created.');
        }
        if ($reservations->isEmpty() && $unfulfilledStack === 0 && ! $owesOpenFulfillment) {
            return $summary;
        }
        if ($missingComponents !== [] && $reservations->isNotEmpty() && $unfulfilledStack === 0 && ! $owesOpenFulfillment) {
            throw new \RuntimeException("Incomplete stack component snapshot for {$lineId} cannot be closed while the order is already fulfilled.");
        }

        $summary['stack_lines']++;
        $orderCreatedAt = $this->lines->orderCreatedAt((array) $event->payload);
        $fulfilledBaseline = max(0, $currentStack - $unfulfilledStack);
        $createdNow = [];
        foreach ($missingComponents as $componentProductId => $quantityPerStack) {
            $baseline = ($owesOpenFulfillment && $unfulfilledStack === 0) ? 0 : $fulfilledBaseline;
            $created = $this->createReservation(
                $event, $lineId, $lineItem, $stackVariant, $componentProductId,
                $quantityPerStack, $currentStack, $baseline, $orderCreatedAt,
            );
            $reservations->push($created);
            if ($created->wasRecentlyCreated) {
                $createdNow[$created->id] = true;
            }
        }

        foreach ($reservations as $reservation) {
            if ($reservation->status === ShopifyStackInventoryReservation::STATUS_WRITTEN_OFF) {
                continue;
            }
            if ($reservation->status === ShopifyStackInventoryReservation::STATUS_RELEASED
                && $reservation->bead_family_id === null) {
                continue;
            }
            $perStack = (int) $reservation->component_quantity_per_stack;
            $required = $currentStack * $perStack;
            $reservation->forceFill(array_filter([
                'stack_quantity_ordered' => $currentStack,
                'total_component_quantity_required' => $required,
                'shopify_order_created_at' => $reservation->shopify_order_created_at ?? $orderCreatedAt,
            ], fn (mixed $value): bool => $value !== null))->save();

            $demandUnfulfilled = isset($createdNow[$reservation->id]) && $owesOpenFulfillment && $unfulfilledStack === 0
                ? $currentStack
                : $unfulfilledStack;
            $targetRemaining = $demandUnfulfilled * $perStack;
            if ($reservation->bead_family_id !== null) {
                $targetRemaining = max(0, $required - (int) $reservation->historical_quantity - (int) $reservation->consumed_quantity);
            }
            $pendingConsume = max(0, (($currentStack - $unfulfilledStack) * $perStack)
                - (int) $reservation->historical_quantity - (int) $reservation->consumed_quantity);
            $remaining = $reservation->remainingReserved();
            if ($targetRemaining > $remaining) {
                $quantity = $targetRemaining - $remaining;
                $this->movements->execute(
                    $reservation,
                    ShopifyStackInventoryMovement::ACTION_RESERVE,
                    $quantity,
                    "order-event:{$event->webhook_id}:{$lineId}",
                );
                $summary['reserved']++;
            } else {
                $releasable = max(0, ($remaining - $targetRemaining) - $pendingConsume);
                if ($releasable > 0) {
                    $this->movements->execute(
                        $reservation,
                        ShopifyStackInventoryMovement::ACTION_RELEASE,
                        $releasable,
                        "order-event:{$event->webhook_id}:{$lineId}",
                    );
                    $summary['released']++;
                } else {
                    $reservation->refreshLedgerStatus();
                    $reservation->save();
                }
            }
        }

        return $summary;
    }

    /** @param array{stack_lines:int,reserved:int,released:int} $summary
     * @return array{stack_lines:int,reserved:int,released:int}
     */
    private function releaseAll(string $orderId, string $eventKey, array $summary): array
    {
        foreach (ShopifyStackInventoryReservation::query()->where('shopify_order_id', $orderId)->get() as $reservation) {
            if ($this->releaseRemaining($reservation, $eventKey)) {
                $summary['released']++;
            }
        }

        return $summary;
    }

    private function createReservation(
        ShopifyStackOrderEvent $event,
        string $lineId,
        array $lineItem,
        Variant $stackVariant,
        int $componentProductId,
        int $quantityPerStack,
        int $stackQuantity,
        int $fulfilledBaseline,
        mixed $orderCreatedAt,
    ): ShopifyStackInventoryReservation {
        $configuredComponentId = $componentProductId;
        $component = Product::query()->with('variants')->find($componentProductId);
        if (! $component instanceof Product) {
            throw new \RuntimeException("Stack component product {$componentProductId} could not be found.");
        }
        $componentVariants = $component->variants
            ->filter(fn (Variant $variant): bool => trim((string) $variant->shopify_inventory_item_id) !== '')
            ->values();
        if ($componentVariants->count() !== 1) {
            throw new \RuntimeException("Stack component product {$componentProductId} must have exactly one inventory-tracked variant.");
        }
        /** @var Variant $componentVariant */
        $componentVariant = $componentVariants->first();
        if (! isset($lineItem['_bead_family_id']) && EngravedBeadConfiguration::fromProduct($component)->participating()) {
            $pool = app(EngravedBeadStockPool::class);
            $member = $pool->member($componentVariant);
            $source = $pool->source($componentVariant);
            if ($member === null || $source === null || $orderCreatedAt === null || $orderCreatedAt->lt($member->family->activated_at)) {
                throw new \RuntimeException('Engraved stack component requires an approved shared source and post-cutover order.');
            }
            $quantityPerStack *= $member->required_beads;
            $componentVariant = $source;
            $component = $source->product;
            $lineItem['_bead_family_id'] = $member->family_id;
            $lineItem['_bead_configuration_version'] = $member->family->configuration_version;
            $lineItem['_bead_location_id'] = $member->family->source_location_id;
            $fulfilledBaseline = 0;
        }
        $engraved = isset($lineItem['_bead_family_id']);
        $locationId = $this->gid('Location', $engraved ? $lineItem['_bead_location_id'] : $this->inventory->resolveLocationId($componentVariant));
        $ledgerPrefix = $engraved
            ? 'leighavenue-cms://engraved-bead-reservations/'
            : 'leighavenue-cms://stack-reservations/';
        $ledgerUri = $ledgerPrefix.rawurlencode($event->shopify_order_id)
            .'/'.rawurlencode($lineId).'/'.$configuredComponentId;
        $baseline = max(0, min($stackQuantity, $fulfilledBaseline)) * $quantityPerStack;

        $reservation = ShopifyStackInventoryReservation::query()->firstOrCreate(
            [
                'shopify_order_id' => $event->shopify_order_id,
                'shopify_order_line_item_id' => $lineId,
                'configured_component_product_id' => $configuredComponentId,
            ],
            [
                'shopify_order_name' => $event->shopify_order_name,
                'shopify_order_created_at' => $orderCreatedAt,
                'stack_product_id' => $stackVariant->product_id,
                'stack_variant_id' => $stackVariant->id,
                'shopify_stack_product_id' => $stackVariant->product?->shopify_id,
                'shopify_stack_variant_id' => $stackVariant->shopify_id,
                'stack_sku' => trim((string) ($stackVariant->sku ?: ($lineItem['sku'] ?? ''))) ?: null,
                'stack_title' => $stackVariant->product?->title ?? ($lineItem['title'] ?? null),
                'stack_quantity_ordered' => $stackQuantity,
                'component_product_id' => $component->id,
                'component_variant_id' => $componentVariant->id,
                'shopify_component_product_id' => $component->shopify_id,
                'shopify_component_variant_id' => $componentVariant->shopify_id,
                'shopify_inventory_item_id' => $componentVariant->shopify_inventory_item_id,
                'component_sku' => $componentVariant->sku,
                'component_title' => $component->title,
                'component_quantity_per_stack' => $quantityPerStack,
                'total_component_quantity_required' => $stackQuantity * $quantityPerStack,
                'reserved_quantity' => $engraved ? 0 : $baseline,
                'consumed_quantity' => $engraved ? 0 : $baseline,
                'historical_quantity' => $engraved ? $baseline : 0,
                'bead_family_id' => $lineItem['_bead_family_id'] ?? null,
                'bead_configuration_version' => $lineItem['_bead_configuration_version'] ?? null,
                'shopify_location_id' => $locationId,
                'ledger_document_uri' => $ledgerUri,
                'status' => ShopifyStackInventoryReservation::STATUS_PENDING_PROCESSING,
            ],
        );
        $reservation->refreshLedgerStatus();
        $reservation->save();

        return $reservation;
    }

    private function guardManualClose(ShopifyStackInventoryReservation $reservation): void
    {
        $reason = $this->fulfillment->manualCloseBlockReason($reservation->shopify_order_id);
        if ($reason === null) {
            return;
        }
        $reservation->forceFill(['error_message' => $reason])->save();

        throw new \RuntimeException(trim((string) ($reservation->shopify_order_name ?: $reservation->shopify_order_id)).': '.$reason);
    }

    private function releaseRemaining(ShopifyStackInventoryReservation $reservation, string $eventKey): bool
    {
        $remaining = $reservation->remainingReserved();
        if ($remaining <= 0) {
            return false;
        }
        $this->movements->execute(
            $reservation,
            ShopifyStackInventoryMovement::ACTION_RELEASE,
            $remaining,
            $eventKey,
        );

        return true;
    }

    private function findVariant(mixed $shopifyVariantId): ?Variant
    {
        $id = trim((string) ($shopifyVariantId ?? ''));
        if ($id === '') {
            return null;
        }

        return Variant::query()->with('product')->whereIn('shopify_id', [$id, $this->gid('ProductVariant', $id)])->first();
    }

    private function findStackDraft(?Variant $variant, array $lineItem): ?NewProductDraft
    {
        return NewProductDraft::configuredStackMatching($variant, $lineItem);
    }

    /** @return array<int, int> */
    private function componentConfiguration(NewProductDraft $draft): array
    {
        return $draft->configuredStackComponentQuantities();
    }

    /**
     * @param  iterable<int, ShopifyStackInventoryReservation>  $reservations
     * @return array<int, int>
     */
    private function missingComponents(iterable $reservations, NewProductDraft $draft): array
    {
        $present = [];
        foreach ($reservations as $reservation) {
            $present[(int) $reservation->configured_component_product_id] = true;
        }
        $missing = [];
        foreach ($this->componentConfiguration($draft) as $productId => $quantity) {
            if (! isset($present[(int) $productId])) {
                $missing[(int) $productId] = (int) $quantity;
            }
        }

        return $missing;
    }

    private function owesOpenFulfillment(string $orderId): bool
    {
        $legacy = str_contains($orderId, '/') ? substr($orderId, strrpos($orderId, '/') + 1) : $orderId;
        $ids = array_values(array_unique(array_filter([$orderId, $this->gid('Order', $orderId), $legacy])));

        return ShopifyFulfillment::query()
            ->whereIn('shopify_order_id', $ids)
            ->whereNotIn('processing_status', [ShopifyFulfillment::STATUS_COMPLETED, ShopifyFulfillment::STATUS_IGNORED])
            ->exists();
    }

    private function beads(): EngravedBeadOrderProcessor
    {
        return $this->engravedBeads ??= new EngravedBeadOrderProcessor($this->movements);
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
