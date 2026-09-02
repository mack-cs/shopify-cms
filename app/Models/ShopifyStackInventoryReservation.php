<?php

namespace App\Models;

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
}
