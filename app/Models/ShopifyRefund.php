<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ShopifyRefund extends Model
{
    use HasFactory;

    protected $fillable = [
        'shopify_refund_id',
        'shopify_order_id',
        'shopify_order_db_id',
        'order_name',
        'refund_created_at_shopify',
        'note',
        'refunded_amount',
        'currency_code',
        'latest_sync_run_id',
        'first_seen_at',
        'last_seen_at',
    ];

    protected $casts = [
        'refund_created_at_shopify' => 'datetime',
        'refunded_amount' => 'decimal:2',
        'first_seen_at' => 'datetime',
        'last_seen_at' => 'datetime',
    ];

    public function order(): BelongsTo
    {
        return $this->belongsTo(ShopifyOrder::class, 'shopify_order_db_id');
    }
}
