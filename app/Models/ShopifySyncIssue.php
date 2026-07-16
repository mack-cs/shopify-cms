<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ShopifySyncIssue extends Model
{
    use HasFactory;

    public const TYPE_INVALID_JSON = 'invalid_json';
    public const TYPE_UNCLASSIFIED_RECORD = 'unclassified_record';
    public const TYPE_UNMATCHED_INVENTORY = 'unmatched_inventory';
    public const TYPE_UNMATCHED_SKU = 'unmatched_sku';
    public const TYPE_DUPLICATE_SKU = 'duplicate_sku';
    public const TYPE_MISSING_SKU = 'missing_sku';

    protected $fillable = [
        'sync_run_id',
        'dataset',
        'issue_type',
        'shopify_id',
        'parent_shopify_id',
        'sku',
        'message',
        'payload',
    ];

    protected $casts = [
        'payload' => 'array',
    ];

    public function syncRun(): BelongsTo
    {
        return $this->belongsTo(ShopifySyncRun::class, 'sync_run_id');
    }
}
