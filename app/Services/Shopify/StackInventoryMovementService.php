<?php

namespace App\Services\Shopify;

use App\Models\ShopifyStackInventoryMovement;
use App\Models\ShopifyStackInventoryReservation;
use App\Models\Variant;
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
    ): ShopifyStackInventoryMovement {
        if ($quantity <= 0) {
            throw new \InvalidArgumentException('Stack inventory movement quantity must be positive.');
        }

        $eventKey = hash('sha256', implode('|', [$sourceEventKey, $reservation->id, $action, $quantity]));
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

        $reservation->loadMissing('componentVariant');
        $variant = $reservation->componentVariant;
        if (! $variant instanceof Variant) {
            $message = 'The snapshotted component variant no longer exists.';
            $this->fail($movement, $reservation, $message);
            throw new \RuntimeException($message);
        }

        $movement->forceFill([
            'status' => ShopifyStackInventoryMovement::STATUS_PROCESSING,
            'attempts' => (int) $movement->attempts + 1,
            'error_message' => null,
        ])->save();

        $reference = 'gid://leigh-avenue-cms/StackInventoryMovement/'.$movement->id;
        try {
            $response = match ($action) {
                ShopifyStackInventoryMovement::ACTION_RESERVE => $this->inventory->moveQuantity(
                    $variant, $quantity, 'available', 'reserved', 'reservation_created', $reference,
                    $reservation->ledger_document_uri, $movement->idempotency_key, $reservation->shopify_location_id,
                ),
                ShopifyStackInventoryMovement::ACTION_RELEASE => $this->inventory->moveQuantity(
                    $variant, $quantity, 'reserved', 'available', 'reservation_deleted', $reference,
                    $reservation->ledger_document_uri, $movement->idempotency_key, $reservation->shopify_location_id,
                ),
                ShopifyStackInventoryMovement::ACTION_CONSUME => $this->inventory->consumeReserved(
                    $variant, $quantity, $reference, $reservation->ledger_document_uri,
                    $movement->idempotency_key, $reservation->shopify_location_id,
                ),
                default => throw new \InvalidArgumentException("Unsupported stack inventory action [{$action}]."),
            };
        } catch (\Throwable $exception) {
            $this->fail($movement, $reservation, $exception->getMessage());
            throw $exception;
        }

        DB::transaction(function () use ($movement, $reservation, $action, $quantity, $response): void {
            $locked = ShopifyStackInventoryReservation::query()->lockForUpdate()->findOrFail($reservation->id);
            $column = match ($action) {
                ShopifyStackInventoryMovement::ACTION_RESERVE => 'reserved_quantity',
                ShopifyStackInventoryMovement::ACTION_CONSUME => 'consumed_quantity',
                ShopifyStackInventoryMovement::ACTION_RELEASE => 'released_quantity',
            };
            $locked->{$column} = (int) $locked->{$column} + $quantity;
            $locked->error_message = null;
            if ($action === ShopifyStackInventoryMovement::ACTION_RESERVE) {
                $locked->reserved_at ??= now();
            } elseif ($action === ShopifyStackInventoryMovement::ACTION_RELEASE) {
                $locked->released_at = now();
            }
            $this->refreshStatus($locked);
            $locked->save();

            $movement->forceFill([
                'status' => ShopifyStackInventoryMovement::STATUS_COMPLETED,
                'shopify_response' => $response,
                'processed_at' => now(),
                'error_message' => null,
            ])->save();
        });

        return $movement->fresh();
    }

    private function refreshStatus(ShopifyStackInventoryReservation $reservation): void
    {
        if ($reservation->remainingReserved() > 0) {
            $reservation->status = ShopifyStackInventoryReservation::STATUS_PENDING;
            $reservation->completed_at = null;

            return;
        }
        if ((int) $reservation->consumed_quantity >= (int) $reservation->total_component_quantity_required) {
            $reservation->status = ShopifyStackInventoryReservation::STATUS_COMPLETED;
            $reservation->completed_at ??= now();

            return;
        }

        $reservation->status = ShopifyStackInventoryReservation::STATUS_RELEASED;
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
