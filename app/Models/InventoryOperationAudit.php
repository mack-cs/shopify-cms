<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class InventoryOperationAudit extends Model
{
    protected $guarded = [];

    protected $casts = [
        'delta' => 'integer',
        'quantity_before' => 'integer',
        'quantity_after' => 'integer',
        'attempts' => 'integer',
        'shopify_response' => 'array',
        'occurred_at' => 'datetime',
    ];

    public function actor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'actor_user_id');
    }

    public function variant(): BelongsTo
    {
        return $this->belongsTo(Variant::class);
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }
}
