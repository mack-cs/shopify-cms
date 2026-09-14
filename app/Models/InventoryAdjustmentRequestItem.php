<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class InventoryAdjustmentRequestItem extends Model
{
    protected $guarded = [];

    protected $casts = [
        'original_inventory_tracked' => 'boolean',
        'requested_inventory_tracked' => 'boolean',
        'original_on_hand_quantity' => 'integer',
        'requested_on_hand_quantity' => 'integer',
        'shopify_update_result' => 'array',
    ];

    public function request(): BelongsTo
    {
        return $this->belongsTo(InventoryAdjustmentRequest::class, 'inventory_adjustment_request_id');
    }

    public function variant(): BelongsTo
    {
        return $this->belongsTo(Variant::class);
    }
}
