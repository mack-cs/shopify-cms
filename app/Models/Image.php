<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

class Image extends Model
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
        'src',
        'image_path',
        'position',
        'alt_text',
    ];

    protected $casts = [
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
