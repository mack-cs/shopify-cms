<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

final class EngravedBeadSellabilityOperation extends Model
{
    protected $guarded = [];

    protected $casts = ['disable' => 'boolean', 'intent' => 'array', 'confirmed_steps' => 'array', 'confirmed_at' => 'datetime'];
}
