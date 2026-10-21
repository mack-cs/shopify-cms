<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ShopifyInventoryAdjustment extends Model
{
    protected $guarded = [];

    protected $casts = [
        'occurred_at' => 'datetime',
        'synced_at' => 'datetime',
        'delta' => 'integer',
    ];
}
