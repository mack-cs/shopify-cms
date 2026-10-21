<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ShopifyInventoryHistorySync extends Model
{
    protected $guarded = [];

    protected $casts = [
        'history_date' => 'date',
        'finished_at' => 'datetime',
        'row_count' => 'integer',
    ];
}
