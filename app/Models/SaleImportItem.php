<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SaleImportItem extends Model
{
    public const STATUS_MATCHED = 'matched';
    public const STATUS_UNMATCHED = 'unmatched';
    public const STATUS_FAILED = 'failed';

    protected $fillable = [
        'sale_import_batch_id',
        'sku',
        'product_id',
        'variant_id',
        'old_price',
        'compare_at_price',
        'sale_price',
        'status',
        'message',
        'payload',
    ];

    protected $casts = [
        'old_price' => 'decimal:2',
        'compare_at_price' => 'decimal:2',
        'sale_price' => 'decimal:2',
        'payload' => 'array',
    ];

    public function batch(): BelongsTo
    {
        return $this->belongsTo(SaleImportBatch::class, 'sale_import_batch_id');
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    public function variant(): BelongsTo
    {
        return $this->belongsTo(Variant::class);
    }
}
