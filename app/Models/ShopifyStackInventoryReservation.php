<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

final class ShopifyStackInventoryReservation extends Model
{
    public const STATUS_PENDING_PROCESSING = 'pending_processing';

    public const STATUS_PENDING = 'pending';

    public const STATUS_COMPLETED = 'completed';

    public const STATUS_RELEASED = 'released';

    public const STATUS_WRITTEN_OFF = 'written_off';

    public const STATUS_FAILED = 'failed';

    protected $guarded = [];

    protected $casts = [
        'shopify_order_created_at' => 'datetime',
        'reserved_at' => 'datetime',
        'completed_at' => 'datetime',
        'released_at' => 'datetime',
    ];

    public function componentVariant(): BelongsTo
    {
        return $this->belongsTo(Variant::class, 'component_variant_id');
    }

    public function movements(): HasMany
    {
        return $this->hasMany(ShopifyStackInventoryMovement::class, 'reservation_id');
    }

    public function remainingReserved(): int
    {
        return max(0, (int) $this->reserved_quantity - (int) $this->consumed_quantity - (int) $this->released_quantity - (int) $this->written_off_quantity);
    }

    public function scopeWithLeftoverReserved(Builder $query): Builder
    {
        return $query->whereRaw('(COALESCE(reserved_quantity, 0) - COALESCE(consumed_quantity, 0) - COALESCE(released_quantity, 0) - COALESCE(written_off_quantity, 0)) > 0');
    }

    public function scopeWithStaleLeftoverReserved(Builder $query): Builder
    {
        $driver = $query->getConnection()->getDriverName();
        $gid = function (string $column) use ($driver): string {
            $prefixed = $driver === 'sqlite'
                ? "'gid://shopify/Order/' || {$column}"
                : "CONCAT('gid://shopify/Order/', {$column})";

            return "CASE WHEN {$column} LIKE 'gid://shopify/%' THEN {$column} ELSE {$prefixed} END";
        };
        $orderGid = $gid('shopify_orders.shopify_order_id');
        $reservationGid = $gid('shopify_stack_inventory_reservations.shopify_order_id');

        return $query->withLeftoverReserved()->whereExists(function ($orders) use ($orderGid, $reservationGid): void {
            $orders->selectRaw('1')
                ->from('shopify_orders')
                ->whereRaw("({$orderGid}) = ({$reservationGid})")
                ->where(function ($closed): void {
                    $closed->whereNotNull('shopify_orders.cancelled_at_shopify')
                        ->orWhereRaw("UPPER(REPLACE(REPLACE(COALESCE(shopify_orders.fulfillment_status, ''), ' ', '_'), '-', '_')) IN ('FULFILLED', 'COMPLETE', 'SHIPPED')");
                });
        });
    }

    public function localOrderIsFulfilledOrCancelled(): bool
    {
        $orderId = trim((string) $this->shopify_order_id);
        $legacy = str_contains($orderId, '/') ? substr($orderId, strrpos($orderId, '/') + 1) : $orderId;
        $gid = str_starts_with($orderId, 'gid://shopify/') ? $orderId : 'gid://shopify/Order/'.$legacy;
        $order = ShopifyOrder::query()->whereIn('shopify_order_id', array_values(array_unique(array_filter([$orderId, $gid, $legacy]))))->first();
        if (! $order instanceof ShopifyOrder) {
            return false;
        }
        if ($order->cancelled_at_shopify !== null) {
            return true;
        }
        $status = strtoupper(str_replace([' ', '-'], '_', trim((string) $order->fulfillment_status)));

        return in_array($status, ['FULFILLED', 'COMPLETE', 'SHIPPED'], true);
    }

    public function refreshLedgerStatus(): void
    {
        if ($this->status === self::STATUS_FAILED) {
            return;
        }
        if ($this->remainingReserved() > 0) {
            $this->status = self::STATUS_PENDING;
            $this->completed_at = null;

            return;
        }

        $required = (int) $this->total_component_quantity_required;
        $consumed = (int) $this->consumed_quantity;
        $released = (int) $this->released_quantity;
        $writtenOff = (int) $this->written_off_quantity;
        if ($writtenOff > 0) {
            $this->status = self::STATUS_WRITTEN_OFF;

            return;
        }
        if ($required > 0 && $consumed >= $required) {
            $this->status = self::STATUS_COMPLETED;
            $this->completed_at ??= now();

            return;
        }
        if ($released > 0) {
            $this->status = self::STATUS_RELEASED;
            $this->released_at ??= now();

            return;
        }

        $this->status = self::STATUS_PENDING_PROCESSING;
    }
}
