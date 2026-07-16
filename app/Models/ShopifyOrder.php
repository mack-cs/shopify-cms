<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ShopifyOrder extends Model
{
    use HasFactory;

    protected $fillable = [
        'shopify_order_id',
        'shopify_order_number',
        'name',
        'created_at_shopify',
        'updated_at_shopify',
        'processed_at_shopify',
        'cancelled_at_shopify',
        'cancel_reason',
        'financial_status',
        'fulfillment_status',
        'currency_code',
        'subtotal_amount',
        'total_amount',
        'discount_amount',
        'shipping_amount',
        'tax_amount',
        'refunded_amount',
        'source_name',
        'is_test',
        'customer_accepts_marketing',
        'billing_country',
        'billing_province',
        'billing_city',
        'shipping_country',
        'shipping_province',
        'shipping_city',
        'tags',
        'latest_sync_run_id',
        'first_seen_at',
        'last_seen_at',
    ];

    protected $casts = [
        'created_at_shopify' => 'datetime',
        'updated_at_shopify' => 'datetime',
        'processed_at_shopify' => 'datetime',
        'cancelled_at_shopify' => 'datetime',
        'is_test' => 'boolean',
        'customer_accepts_marketing' => 'boolean',
        'tags' => 'array',
        'first_seen_at' => 'datetime',
        'last_seen_at' => 'datetime',
        'subtotal_amount' => 'decimal:2',
        'total_amount' => 'decimal:2',
        'discount_amount' => 'decimal:2',
        'shipping_amount' => 'decimal:2',
        'tax_amount' => 'decimal:2',
        'refunded_amount' => 'decimal:2',
    ];

    public function latestSyncRun(): BelongsTo
    {
        return $this->belongsTo(ShopifySyncRun::class, 'latest_sync_run_id');
    }

    public function items(): HasMany
    {
        return $this->hasMany(ShopifyOrderItem::class, 'shopify_order_db_id');
    }

    public function refunds(): HasMany
    {
        return $this->hasMany(ShopifyRefund::class, 'shopify_order_db_id');
    }

    public function discounts(): HasMany
    {
        return $this->hasMany(ShopifyDiscountApplication::class, 'shopify_order_db_id');
    }
}
