<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class InventoryWriteIntent extends Model
{
    protected $guarded = [];

    protected $casts = ['payload' => 'array'];
}
