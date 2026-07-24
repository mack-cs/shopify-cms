<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ShopifyRefundLineItem extends Model
{
    protected $fillable = [
        'shopify_refund_line_item_id',
        'shopify_refund_id',
        'shopify_refund_db_id',
        'shopify_order_id',
        'shopify_order_db_id',
        'shopify_line_item_id',
        'shopify_order_item_db_id',
        'quantity',
        'subtotal_amount',
        'tax_amount',
        'currency_code',
        'restocked',
        'restock_type',
        'shopify_location_id',
        'location_name',
        'latest_sync_run_id',
        'first_seen_at',
        'last_seen_at',
    ];

    protected $casts = [
        'quantity' => 'integer',
        'subtotal_amount' => 'decimal:2',
        'tax_amount' => 'decimal:2',
        'restocked' => 'boolean',
        'first_seen_at' => 'datetime',
        'last_seen_at' => 'datetime',
    ];

    public function refund(): BelongsTo
    {
        return $this->belongsTo(ShopifyRefund::class, 'shopify_refund_db_id');
    }

    public function order(): BelongsTo
    {
        return $this->belongsTo(ShopifyOrder::class, 'shopify_order_db_id');
    }

    public function orderItem(): BelongsTo
    {
        return $this->belongsTo(ShopifyOrderItem::class, 'shopify_order_item_db_id');
    }
}
