<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ShopifyDiscountApplication extends Model
{
    use HasFactory;

    protected $fillable = [
        'discount_key',
        'shopify_order_id',
        'shopify_order_db_id',
        'allocation_method',
        'target_selection',
        'target_type',
        'value_type',
        'discount_amount',
        'discount_percentage',
        'currency_code',
        'latest_sync_run_id',
        'first_seen_at',
        'last_seen_at',
    ];

    protected $casts = [
        'discount_amount' => 'decimal:2',
        'discount_percentage' => 'decimal:4',
        'first_seen_at' => 'datetime',
        'last_seen_at' => 'datetime',
    ];

    public function order(): BelongsTo
    {
        return $this->belongsTo(ShopifyOrder::class, 'shopify_order_db_id');
    }
}
