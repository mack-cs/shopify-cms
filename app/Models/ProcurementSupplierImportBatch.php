<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ProcurementSupplierImportBatch extends Model
{
    protected $guarded = [];
    protected $casts = ['preview_rows' => 'array', 'errors' => 'array', 'confirmed_at' => 'datetime', 'completed_at' => 'datetime'];
}
