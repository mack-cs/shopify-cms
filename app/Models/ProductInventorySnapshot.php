<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;
use LogicException;

class ProductInventorySnapshot extends Model
{
    public const SOURCE_SHOPIFY_REFRESH = 'shopify_refresh';
    public const SOURCE_LOCAL_UPDATE = 'local_update';
    public const SOURCE_STOCK_IMPORT = 'stock_import';
    public const SOURCE_BUNDLE_COMPONENT_RULE = 'bundle_component_rule';

    protected $fillable = [
        'product_id',
        'observed_by',
        'product_title',
        'product_handle',
        'product_shopify_id',
        'checked_at',
        'checked_date',
        'source',
        'product_status',
        'is_sellable',
        'is_out_of_stock',
        'sellability_reason',
        'variant_count',
        'tracked_variant_count',
        'untracked_variant_count',
        'unknown_inventory_variant_count',
        'sellable_variant_count',
        'out_of_stock_variant_count',
        'total_inventory_qty',
        'primary_variant_qty',
        'variant_summary',
    ];

    protected $casts = [
        'checked_at' => 'datetime',
        'checked_date' => 'date',
        'is_sellable' => 'boolean',
        'is_out_of_stock' => 'boolean',
        'variant_summary' => 'array',
    ];

    protected static function booted(): void
    {
        static::updating(fn (): never => throw new LogicException('Inventory snapshots are append-only.'));
        static::deleting(fn (): never => throw new LogicException('Inventory snapshots are append-only.'));
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    public function observedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'observed_by');
    }

    public function checkedDateLabel(): string
    {
        return $this->checked_date instanceof Carbon
            ? $this->checked_date->toDateString()
            : (string) $this->checked_date;
    }
}
