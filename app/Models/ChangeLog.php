<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ChangeLog extends Model
{
      protected $fillable = [
        'import_id',
        'product_id',
        'shopify_row_id',
        'changed_by',
        'model_type',
        'model_id',
        'field',
        'old_value',
        'new_value',
    ];

    public function import(): BelongsTo
    {
        return $this->belongsTo(Import::class);
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    public function shopifyRow(): BelongsTo
    {
        return $this->belongsTo(ShopifyRow::class);
    }

    public function changedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'changed_by');
    }

    protected static function booted(): void
    {
        static::updating(fn () => throw new \Exception('ChangeLog entries are immutable.'));
        static::deleting(fn () => throw new \Exception('ChangeLog entries are immutable.'));
    }

}
