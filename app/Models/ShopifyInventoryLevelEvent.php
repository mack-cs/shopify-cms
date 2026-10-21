<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ShopifyInventoryLevelEvent extends Model
{
    protected $guarded = [];

    protected $casts = [
        'available' => 'integer',
        'shopify_updated_at' => 'datetime',
        'received_at' => 'datetime',
        'payload' => 'array',
    ];
}
