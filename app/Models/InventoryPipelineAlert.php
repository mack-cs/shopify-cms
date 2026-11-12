<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

final class InventoryPipelineAlert extends Model
{
    protected $guarded = [];

    protected $casts = ['details' => 'array', 'available_at' => 'datetime', 'sent_at' => 'datetime'];
}
