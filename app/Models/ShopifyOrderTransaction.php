<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ShopifyOrderTransaction extends Model
{
    protected $fillable = [
        'shopify_transaction_id',
        'shopify_order_id',
        'shopify_order_db_id',
        'parent_transaction_id',
        'kind',
        'status',
        'gateway',
        'formatted_gateway',
        'amount',
        'currency_code',
        'created_at_shopify',
        'processed_at_shopify',
        'error_code',
        'manual_payment_gateway',
        'is_test',
        'latest_sync_run_id',
        'first_seen_at',
        'last_seen_at',
    ];

    protected $casts = [
        'amount' => 'decimal:2',
        'created_at_shopify' => 'datetime',
        'processed_at_shopify' => 'datetime',
        'manual_payment_gateway' => 'boolean',
        'is_test' => 'boolean',
        'first_seen_at' => 'datetime',
        'last_seen_at' => 'datetime',
    ];

    public function order(): BelongsTo
    {
        return $this->belongsTo(ShopifyOrder::class, 'shopify_order_db_id');
    }
}
