<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ShopifyAudit extends Model
{
    public const TYPE_COMPLEMENTARY_PRODUCTS = 'complementary_products';
    public const STATUS_HEALTHY = 'healthy';
    public const STATUS_FLAGGED = 'flagged';

    protected $fillable = [
        'product_id',
        'audit_type',
        'status',
        'needs_attention',
        'local_saved_count',
        'local_valid_count',
        'shopify_current_count',
        'shopify_valid_count',
        'details',
        'last_checked_at',
        'last_notified_at',
    ];

    protected $casts = [
        'needs_attention' => 'boolean',
        'details' => 'array',
        'last_checked_at' => 'datetime',
        'last_notified_at' => 'datetime',
    ];

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }
}
