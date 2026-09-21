<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ShopifyInventorySnapshot extends Model
{
    use HasFactory;

    protected $fillable = [
        'sync_run_id',
        'business_date',
        'snapshot_requested_at',
        'snapshot_completed_at',
        'shopify_inventory_item_id',
        'shopify_inventory_level_id',
        'shopify_product_id',
        'shopify_variant_id',
        'shopify_location_id',
        'location_name',
        'location_active',
        'sku',
        'barcode',
        'product_title',
        'product_handle',
        'product_type',
        'vendor',
        'product_status',
        'variant_title',
        'tracked',
        'requires_shipping',
        'variant_price',
        'available',
        'on_hand',
        'committed',
        'incoming',
        'reserved',
        'damaged',
        'quality_control',
        'safety_stock',
    ];

    protected $casts = [
        'business_date' => 'date',
        'snapshot_requested_at' => 'datetime',
        'snapshot_completed_at' => 'datetime',
        'location_active' => 'boolean',
        'tracked' => 'boolean',
        'requires_shipping' => 'boolean',
        'variant_price' => 'decimal:2',
    ];

    public function syncRun(): BelongsTo
    {
        return $this->belongsTo(ShopifySyncRun::class, 'sync_run_id');
    }
}
