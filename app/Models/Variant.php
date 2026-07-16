<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Variant extends Model
{
    public const SYNC_STATE_SYNCED = 'synced';
    public const SYNC_STATE_LOCAL_NEW = 'local_new';
    public const SYNC_STATE_LOCAL_UPDATED = 'local_updated';
    public const SYNC_STATE_LOCAL_DELETED = 'local_deleted';
    public const SYNC_STATE_REMOTE_DELETED = 'remote_deleted';
    public const SYNC_STATE_CONFLICT = 'conflict';

    protected $fillable = [
        'product_id',
        'image_id',
        'shopify_id',
        'shopify_inventory_item_id',
        'sync_state',
        'local_dirty',
        'last_shopify_seen_at',
        'last_synced_at',

        'sku',
        'barcode',

        'option1_name',
        'option1_value',
        'option2_name',
        'option2_value',
        'option3_name',
        'option3_value',

        'price',
        'compare_at_price',

        'inventory_qty',
        'current_inventory_quantity',
        'current_available_quantity',
        'current_on_hand_quantity',
        'current_committed_quantity',
        'current_incoming_quantity',
        'current_reserved_quantity',
        'current_damaged_quantity',
        'current_quality_control_quantity',
        'current_safety_stock_quantity',
        'inventory_location_count',
        'inventory_policy',
        'inventory_tracked',
        'inventory_last_synced_at',
        'inventory_pushed_at',
        'inventory_sync_batch_id',
        'inventory_local_dirty',
        'inventory_sync_error',

        'requires_shipping',
        'taxable',

        'weight',
        'weight_unit',

        'position',
    ];


    protected $casts = [
        'price' => 'decimal:2',
        'compare_at_price' => 'decimal:2',
        'weight' => 'decimal:3',

        'requires_shipping' => 'boolean',
        'taxable' => 'boolean',
        'inventory_tracked' => 'boolean',
        'inventory_local_dirty' => 'boolean',
        'local_dirty' => 'boolean',
        'last_shopify_seen_at' => 'datetime',
        'last_synced_at' => 'datetime',
        'current_inventory_quantity' => 'integer',
        'current_available_quantity' => 'integer',
        'current_on_hand_quantity' => 'integer',
        'current_committed_quantity' => 'integer',
        'current_incoming_quantity' => 'integer',
        'current_reserved_quantity' => 'integer',
        'current_damaged_quantity' => 'integer',
        'current_quality_control_quantity' => 'integer',
        'current_safety_stock_quantity' => 'integer',
        'inventory_location_count' => 'integer',
        'inventory_last_synced_at' => 'datetime',
        'inventory_pushed_at' => 'datetime',
    ];

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    public function image(): BelongsTo
    {
        return $this->belongsTo(Image::class);
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->whereNotIn('sync_state', [
            self::SYNC_STATE_LOCAL_DELETED,
            self::SYNC_STATE_REMOTE_DELETED,
        ]);
    }
}
