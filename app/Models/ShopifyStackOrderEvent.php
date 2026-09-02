<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

final class ShopifyStackOrderEvent extends Model
{
    public const STATUS_PENDING = 'pending';

    public const STATUS_PROCESSING = 'processing';

    public const STATUS_COMPLETED = 'completed';

    public const STATUS_FAILED = 'failed';

    protected $guarded = [];

    protected $casts = [
        'payload' => 'array',
        'shopify_updated_at' => 'datetime',
        'processed_at' => 'datetime',
    ];
}
