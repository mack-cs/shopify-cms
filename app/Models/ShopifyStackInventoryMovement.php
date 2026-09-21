<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

final class ShopifyStackInventoryMovement extends Model
{
    public const ACTION_RESERVE = 'reserve';

    public const ACTION_CONSUME = 'consume';

    public const ACTION_RELEASE = 'release';

    public const STATUS_PENDING = 'pending';

    public const STATUS_PROCESSING = 'processing';

    public const STATUS_COMPLETED = 'completed';

    public const STATUS_FAILED = 'failed';

    protected $guarded = [];

    protected $casts = [
        'shopify_response' => 'array',
        'processed_at' => 'datetime',
    ];

    public function reservation(): BelongsTo
    {
        return $this->belongsTo(ShopifyStackInventoryReservation::class, 'reservation_id');
    }
}
