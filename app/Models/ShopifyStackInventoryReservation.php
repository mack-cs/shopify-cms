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
        return max(0, (int) $this->reserved_quantity - (int) $this->consumed_quantity - (int) $this->released_quantity);
    }

    public function scopeWithLeftoverReserved(Builder $query): Builder
    {
        return $query->whereRaw('(COALESCE(reserved_quantity, 0) - COALESCE(consumed_quantity, 0) - COALESCE(released_quantity, 0)) > 0');
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
