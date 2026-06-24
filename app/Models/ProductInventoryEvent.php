<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;

class ProductInventoryEvent extends Model
{
    public const TYPE_FIRST_SEEN_UNSELLABLE = 'first_seen_unsellable';
    public const TYPE_BECAME_UNSELLABLE = 'became_unsellable';
    public const TYPE_BECAME_SELLABLE = 'became_sellable';
    public const TYPE_FIRST_SEEN_OUT_OF_STOCK = 'first_seen_out_of_stock';
    public const TYPE_BECAME_OUT_OF_STOCK = 'became_out_of_stock';
    public const TYPE_LEFT_OUT_OF_STOCK = 'left_out_of_stock';
    public const TYPE_STATUS_CHANGED = 'status_changed';

    protected $fillable = [
        'product_id',
        'product_inventory_snapshot_id',
        'previous_product_inventory_snapshot_id',
        'observed_by',
        'product_title',
        'product_handle',
        'product_shopify_id',
        'event_type',
        'occurred_at',
        'source',
        'from_is_sellable',
        'to_is_sellable',
        'from_is_out_of_stock',
        'to_is_out_of_stock',
        'from_status',
        'to_status',
        'from_reason',
        'to_reason',
        'metadata',
    ];

    protected $casts = [
        'occurred_at' => 'datetime',
        'from_is_sellable' => 'boolean',
        'to_is_sellable' => 'boolean',
        'from_is_out_of_stock' => 'boolean',
        'to_is_out_of_stock' => 'boolean',
        'metadata' => 'array',
    ];

    protected static function booted(): void
    {
        static::updating(fn (): never => throw new LogicException('Inventory events are append-only.'));
        static::deleting(fn (): never => throw new LogicException('Inventory events are append-only.'));
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    public function snapshot(): BelongsTo
    {
        return $this->belongsTo(ProductInventorySnapshot::class, 'product_inventory_snapshot_id');
    }

    public function previousSnapshot(): BelongsTo
    {
        return $this->belongsTo(ProductInventorySnapshot::class, 'previous_product_inventory_snapshot_id');
    }

    public function observedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'observed_by');
    }
}
