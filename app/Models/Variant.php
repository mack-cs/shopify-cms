<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

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
        'shopify_id',
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
        'inventory_policy',

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
        'local_dirty' => 'boolean',
        'last_shopify_seen_at' => 'datetime',
        'last_synced_at' => 'datetime',
    ];

    public function scopeActive(Builder $query): Builder
    {
        return $query->whereNotIn('sync_state', [
            self::SYNC_STATE_LOCAL_DELETED,
            self::SYNC_STATE_REMOTE_DELETED,
        ]);
    }
}
