<?php

namespace App\Services\Shopify;

use App\Models\EngravedBeadFamily;
use App\Models\EngravedBeadFamilyMember;
use App\Models\ShopifyStackInventoryMovement;
use App\Models\ShopifyStackInventoryReservation;
use App\Models\Variant;
use App\Services\EngravedBeads\EngravedBeadWriteGuard;
use App\Services\Inventory\InventoryAuditContext;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

final class StackInventoryMovementService
{
    public function __construct(private readonly ShopifyInventoryAdjustmentService $inventory) {}

    public function execute(
        ShopifyStackInventoryReservation $reservation,
        string $action,
        int $quantity,
        string $sourceEventKey,
        ?int $actorUserId = null,
    ): ShopifyStackInventoryMovement {
        $pool = implode('|', [$reservation->shopify_inventory_item_id, $reservation->shopify_location_id]);

        return Cache::lock('inventory-pool:'.hash('sha256', $pool), 420)->block(5,
            fn () => $this->executeLocked($reservation->fresh(), $action, $quantity, $sourceEventKey, $actorUserId));
    }

    private function executeLocked(
        ShopifyStackInventoryReservation $reservation,
        string $action,
        int $quantity,
        string $sourceEventKey,
        ?int $actorUserId = null,
    ): ShopifyStackInventoryMovement {
        if ($quantity <= 0) {
            throw new \InvalidArgumentException('Stack inventory movement quantity must be positive.');
        }

        // Identity belongs to the immutable business event, never a clamped remainder.
        $eventKey = hash('sha256', implode('|', [$sourceEventKey, $reservation->id, $action]));
        $existing = ShopifyStackInventoryMovement::query()->where('event_key', $eventKey)->first();
        if ($reservation->bead_family_id !== null && $existing === null
            && ShopifyStackInventoryMovement::query()->where('status', '!=', ShopifyStackInventoryMovement::STATUS_COMPLETED)
                ->whereHas('reservation', fn ($q) => $q->where('shopify_inventory_item_id', $reservation->shopify_inventory_item_id)
                    ->where('shopify_location_id', $reservation->shopify_location_id))->exists()) {
            throw new \RuntimeException('An earlier physical movement is unconfirmed; reconcile it before accepting another event.');
        }
        $movement = ShopifyStackInventoryMovement::query()->firstOrCreate(
            ['event_key' => $eventKey],
            [
                'reservation_id' => $reservation->id,
                'action' => $action,
                'quantity' => $quantity,
                'idempotency_key' => (string) Str::uuid(),
                'status' => ShopifyStackInventoryMovement::STATUS_PENDING,
            ],
        );
        if ($movement->status === ShopifyStackInventoryMovement::STATUS_COMPLETED) {
            return $movement;
        }
        // Retry the persisted intent after remote success/local failure, including its quantity.
        $quantity = (int) $movement->quantity;
        $engraved = $reservation->bead_family_id !== null
            || str_starts_with((string) $reservation->ledger_document_uri, 'leighavenue-cms://engraved-bead-reservations/');
        if ($engraved && (! config('engraved_beads.enabled', true) || ! config('engraved_beads.inventory_writes_enabled', false))) {
            throw new \RuntimeException('Engraved bead inventory writes are disabled; operation remains pending.');
        }
        if ($engraved && $reservation->bead_family_id === null) {
            throw new \RuntimeException('Legacy independent bead reservation requires explicit reconciliation before inventory mutations.');
        }

        if ((int) $movement->attempts > 0 && $movement->created_at !== null && $movement->created_at->lt(now()->subHours(23))) {
            throw new \RuntimeException('Remote idempotency retention may expire; explicit reconciliation is required before retrying.');
        }
        if ($engraved) {
            app(EngravedBeadWriteGuard::class)->check();
            $family = EngravedBeadFamily::query()->findOrFail($reservation->bead_family_id);
            if ($family->status !== 'active' || $family->source_inventory_item_id !== $reservation->shopify_inventory_item_id
                || $family->source_location_id !== $reservation->shopify_location_id) {
                throw new \RuntimeException('Family mapping discrepancy blocks physical movements.');
            }
            if (in_array($action, [ShopifyStackInventoryMovement::ACTION_CONSUME, ShopifyStackInventoryMovement::ACTION_RELEASE, ShopifyStackInventoryMovement::ACTION_WRITE_OFF], true)
                && $quantity > $reservation->remainingReserved()) {
                throw new \RuntimeException('Movement exceeds the outstanding reservation.');
            }
            if ($action === ShopifyStackInventoryMovement::ACTION_RESTOCK
                && $quantity > (int) $reservation->consumed_quantity - (int) $reservation->restocked_quantity) {
                throw new \RuntimeException('Restock exceeds confirmed CMS consumption.');
            }
        }
        $reservation->loadMissing('componentVariant');
        $variant = $reservation->componentVariant;
        if (! $variant instanceof Variant) {
            $message = 'The snapshotted component variant no longer exists.';
            $this->fail($movement, $reservation, $message);
            throw new \RuntimeException($message);
        }
        if ($variant->shopify_inventory_item_id !== $reservation->shopify_inventory_item_id) {
            throw new \RuntimeException('The inventory identity changed; the snapshotted movement must be reconciled.');
        }
        if ($engraved && $action === ShopifyStackInventoryMovement::ACTION_RESERVE && $movement->bead_intent === null) {
            $live = $this->inventory->currentQuantities($variant, $reservation->shopify_location_id);
            $fromAvailable = min($quantity, max(0, (int) $live['available']));
            $fromHeld = $quantity - $fromAvailable;
            if ($fromHeld > (int) $family->automation_held_quantity) {
                throw new \RuntimeException('Insufficient physical beads to reserve this order; reconciliation required.');
            }
            $movement->update(['bead_intent' => ['available' => $fromAvailable, 'safety_stock' => $fromHeld]]);
        }
        if ($engraved && $movement->bead_intent === null
            && in_array($action, [ShopifyStackInventoryMovement::ACTION_RELEASE, ShopifyStackInventoryMovement::ACTION_RESTOCK], true)) {
            $hold = EngravedBeadFamilyMember::query()->where('family_id', $reservation->bead_family_id)
                ->where('required_beads', 1)->where('automation_disabled', true)->exists();
            $movement->update(['bead_intent' => ['to_safety_stock' => $hold]]);
        }
        $heldTarget = (bool) data_get($movement->bead_intent, 'to_safety_stock', false);
        $safetyLedger = 'leighavenue-cms://bead-source-safety/'.$reservation->bead_family_id;

        $movement->forceFill([
            'status' => ShopifyStackInventoryMovement::STATUS_PROCESSING,
            'attempts' => (int) $movement->attempts + 1,
            'error_message' => null,
        ])->save();

        $reference = 'gid://leigh-avenue-cms/StackInventoryMovement/'.$movement->id;
        $context = new InventoryAuditContext(
            correlationKey: (string) $movement->idempotency_key,
            workflow: match ($action) {
                ShopifyStackInventoryMovement::ACTION_RELEASE => 'stack_release',
                ShopifyStackInventoryMovement::ACTION_CONSUME => 'stack_consume',
                ShopifyStackInventoryMovement::ACTION_WRITE_OFF => 'stack_write_off',
                ShopifyStackInventoryMovement::ACTION_RESTOCK => 'engraved_bead_restock',
                default => 'stack_reserve',
            },
            action: $action,
            actorType: $action === ShopifyStackInventoryMovement::ACTION_WRITE_OFF && $actorUserId ? 'user' : 'system',
            userId: $action === ShopifyStackInventoryMovement::ACTION_WRITE_OFF ? $actorUserId : null,
            execution: 'queue',
            processName: $action === ShopifyStackInventoryMovement::ACTION_WRITE_OFF ? 'Stack reservation delete' : 'Order processing',
            explanation: match ($action) {
                ShopifyStackInventoryMovement::ACTION_RELEASE => 'The stack reservation was released.',
                ShopifyStackInventoryMovement::ACTION_CONSUME => 'Reserved component inventory was consumed when the stack was fulfilled.',
                ShopifyStackInventoryMovement::ACTION_WRITE_OFF => 'Reserved units were removed from stock and were not returned to available.',
                ShopifyStackInventoryMovement::ACTION_RESTOCK => 'Additional engraved beads were restored because Shopify restocked the returned units.',
                default => 'Component inventory was reserved because an order included this stack.',
            },
            variantId: $variant->id ? (int) $variant->id : null,
            productId: $variant->product_id ? (int) $variant->product_id : null,
            sku: $reservation->component_sku,
            inventoryItemId: $reservation->shopify_inventory_item_id,
            locationId: $reservation->shopify_location_id,
            referenceDocumentUri: $reference,
            relatedType: ShopifyStackInventoryMovement::class,
            relatedId: (int) $movement->id,
        );
        try {
            $response = match ($action) {
                ShopifyStackInventoryMovement::ACTION_RESERVE => $engraved ? $this->reserveBeads($movement, $reservation, $variant, $reference, $context) : $this->inventory->moveQuantity(
                    $variant, $quantity, 'available', 'reserved', 'reservation_created', $reference,
                    $reservation->ledger_document_uri, $movement->idempotency_key, $reservation->shopify_location_id,
                    $context,
                ),
                ShopifyStackInventoryMovement::ACTION_RELEASE => $this->inventory->moveQuantity(
                    $variant, $quantity, 'reserved', $heldTarget ? 'safety_stock' : 'available', 'reservation_deleted', $reference,
                    $heldTarget ? $safetyLedger : $reservation->ledger_document_uri, $movement->idempotency_key, $reservation->shopify_location_id,
                    $context, $heldTarget ? $reservation->ledger_document_uri : null,
                ),
                ShopifyStackInventoryMovement::ACTION_CONSUME => $this->inventory->consumeReserved(
                    $variant, $quantity, $reference, $reservation->ledger_document_uri,
                    $movement->idempotency_key, $reservation->shopify_location_id, $context,
                ),
                ShopifyStackInventoryMovement::ACTION_WRITE_OFF => $this->inventory->writeOffReserved(
                    $variant, $quantity, $reference, $reservation->ledger_document_uri,
                    $movement->idempotency_key, $reservation->shopify_location_id, $context,
                ),
                ShopifyStackInventoryMovement::ACTION_RESTOCK => $this->inventory->restockAvailable(
                    $variant, $quantity, $reservation->ledger_document_uri,
                    $movement->idempotency_key, $reservation->shopify_location_id, $context,
                    $heldTarget ? 'safety_stock' : 'available', $heldTarget ? $safetyLedger : null,
                ),
                default => throw new \InvalidArgumentException("Unsupported stack inventory action [{$action}]."),
            };
        } catch (\Throwable $exception) {
            $this->fail($movement, $reservation, $exception->getMessage());
            throw $exception;
        }

        DB::transaction(function () use ($movement, $reservation, $action, $quantity, $response): void {
            $movement = ShopifyStackInventoryMovement::query()->lockForUpdate()->findOrFail($movement->id);
            if ($movement->status === ShopifyStackInventoryMovement::STATUS_COMPLETED) {
                return;
            }
            $locked = ShopifyStackInventoryReservation::query()->lockForUpdate()->findOrFail($reservation->id);
            $column = match ($action) {
                ShopifyStackInventoryMovement::ACTION_RESERVE => 'reserved_quantity',
                ShopifyStackInventoryMovement::ACTION_CONSUME => 'consumed_quantity',
                ShopifyStackInventoryMovement::ACTION_RELEASE => 'released_quantity',
                ShopifyStackInventoryMovement::ACTION_WRITE_OFF => 'written_off_quantity',
                ShopifyStackInventoryMovement::ACTION_RESTOCK => 'restocked_quantity',
            };
            $locked->{$column} = (int) $locked->{$column} + $quantity;
            $locked->error_message = null;
            if ($locked->status === ShopifyStackInventoryReservation::STATUS_FAILED) {
                $locked->status = ShopifyStackInventoryReservation::STATUS_PENDING_PROCESSING;
            }
            if ($action === ShopifyStackInventoryMovement::ACTION_RESERVE) {
                $locked->reserved_at ??= now();
            } elseif ($action === ShopifyStackInventoryMovement::ACTION_RELEASE) {
                $locked->released_at = now();
            }
            $locked->refreshLedgerStatus();
            $locked->save();
            if ($action === ShopifyStackInventoryMovement::ACTION_RESERVE && (int) data_get($movement->bead_intent, 'safety_stock', 0) > 0) {
                EngravedBeadFamily::query()->whereKey($reservation->bead_family_id)
                    ->decrement('automation_held_quantity', (int) $movement->bead_intent['safety_stock']);
            }
            if (data_get($movement->bead_intent, 'to_safety_stock', false)) {
                EngravedBeadFamily::query()->whereKey($reservation->bead_family_id)->increment('automation_held_quantity', $quantity);
            }

            $movement->forceFill([
                'status' => ShopifyStackInventoryMovement::STATUS_COMPLETED,
                'shopify_response' => $response,
                'processed_at' => now(),
                'error_message' => null,
            ])->save();
        });

        return $movement->fresh();
    }

    private function reserveBeads(ShopifyStackInventoryMovement $movement, ShopifyStackInventoryReservation $reservation,
        Variant $variant, string $reference, InventoryAuditContext $context): array
    {
        $responses = [];
        foreach ($movement->bead_intent as $from => $quantity) {
            if ($quantity <= 0) {
                continue;
            }
            app(EngravedBeadWriteGuard::class)->check();
            $responses[$from] = $this->inventory->moveQuantity($variant, $quantity, $from, 'reserved', 'reservation_created',
                $reference, $reservation->ledger_document_uri, hash('sha256', $movement->idempotency_key.'|'.$from),
                $reservation->shopify_location_id, $context,
                $from === 'safety_stock' ? 'leighavenue-cms://bead-source-safety/'.$reservation->bead_family_id : null);
        }

        return $responses;
    }

    private function fail(
        ShopifyStackInventoryMovement $movement,
        ShopifyStackInventoryReservation $reservation,
        string $message,
    ): ShopifyStackInventoryMovement {
        $movement->forceFill([
            'status' => ShopifyStackInventoryMovement::STATUS_FAILED,
            'error_message' => $message,
        ])->save();
        $reservation->forceFill([
            'status' => ShopifyStackInventoryReservation::STATUS_FAILED,
            'error_message' => $message,
        ])->save();

        return $movement;
    }
}
