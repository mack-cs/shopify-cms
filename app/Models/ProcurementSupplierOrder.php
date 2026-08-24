<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ProcurementSupplierOrder extends Model
{
    protected $guarded = [];

    public function lines(): HasMany
    {
        return $this->hasMany(ProcurementSupplierOrderLine::class, 'supplier_order_id');
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
