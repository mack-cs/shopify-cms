<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ShopifyMissingProduct extends Model
{
    protected $fillable = [
        'import_id',
        'previous_import_id',
        'previous_product_id',
        'handle',
        'title',
        'shopify_id',
        'vendor',
        'status',
        'detected_at',
    ];

    protected $casts = [
        'detected_at' => 'datetime',
    ];

    public function import(): BelongsTo
    {
        return $this->belongsTo(Import::class);
    }

    public function previousImport(): BelongsTo
    {
        return $this->belongsTo(Import::class, 'previous_import_id');
    }

    public function previousProduct(): BelongsTo
    {
        return $this->belongsTo(Product::class, 'previous_product_id');
    }
}
