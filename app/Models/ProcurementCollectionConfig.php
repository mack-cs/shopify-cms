<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

final class ProcurementCollectionConfig extends Model
{
    protected $guarded = [];

    protected $casts = ['is_active' => 'boolean'];
}
