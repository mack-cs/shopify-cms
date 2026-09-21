<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ShopifyImageImportItem extends Model
{
    public const STATUS_PENDING = 'pending';
    public const STATUS_UPDATED = 'updated';
    public const STATUS_FAILED = 'failed';
    public const STATUS_SKIPPED = 'skipped';

    protected $fillable = [
        'batch_id',
        'sku',
        's3_key',
        'product_id',
        'shopify_product_id',
        'status',
        'message',
    ];

    public function batch(): BelongsTo
    {
        return $this->belongsTo(ShopifyImageImportBatch::class, 'batch_id');
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }
}
