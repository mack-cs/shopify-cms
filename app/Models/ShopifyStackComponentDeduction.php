<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

final class ShopifyStackComponentDeduction extends Model
{
    public const STATUS_PENDING = 'pending';

    public const STATUS_PROCESSING = 'processing';

    public const STATUS_COMPLETED = 'completed';

    public const STATUS_FAILED = 'failed';

    protected $guarded = [];

    protected $casts = [
        'shopify_response' => 'array',
        'processing_started_at' => 'datetime',
        'processed_at' => 'datetime',
    ];

    public function fulfillment(): BelongsTo
    {
        return $this->belongsTo(ShopifyFulfillment::class, 'shopify_fulfillment_id');
    }

    public function componentVariant(): BelongsTo
    {
        return $this->belongsTo(Variant::class, 'component_variant_id');
    }
}
