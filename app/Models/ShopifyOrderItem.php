<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ShopifyOrderItem extends Model
{
    use HasFactory;

    protected $fillable = [
        'shopify_line_item_id',
        'shopify_order_id',
        'shopify_order_db_id',
        'shopify_product_id',
        'shopify_variant_id',
        'sku',
        'title',
        'quantity',
        'vendor',
        'taxable',
        'requires_shipping',
        'original_unit_price',
        'discounted_total',
        'total_discount',
        'currency_code',
        'product_title',
        'product_handle',
        'product_type',
        'product_vendor',
        'product_status',
        'variant_title',
        'variant_sku',
        'barcode',
        'variant_price_at_export',
        'variant_inventory_quantity_at_export',
        'order_created_at_shopify',
        'order_updated_at_shopify',
        'latest_sync_run_id',
        'first_seen_at',
        'last_seen_at',
    ];

    protected $casts = [
        'quantity' => 'integer',
        'taxable' => 'boolean',
        'requires_shipping' => 'boolean',
        'original_unit_price' => 'decimal:2',
        'discounted_total' => 'decimal:2',
        'total_discount' => 'decimal:2',
        'variant_price_at_export' => 'decimal:2',
        'variant_inventory_quantity_at_export' => 'integer',
        'order_created_at_shopify' => 'datetime',
        'order_updated_at_shopify' => 'datetime',
        'first_seen_at' => 'datetime',
        'last_seen_at' => 'datetime',
    ];

    public function order(): BelongsTo
    {
        return $this->belongsTo(ShopifyOrder::class, 'shopify_order_db_id');
    }

    public function latestSyncRun(): BelongsTo
    {
        return $this->belongsTo(ShopifySyncRun::class, 'latest_sync_run_id');
    }
}
