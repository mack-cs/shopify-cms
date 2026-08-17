<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ProcurementSupplierOrder extends Model
{
    protected $guarded = [];

    public function lines(): HasMany
    {
        return $this->hasMany(ProcurementSupplierOrderLine::class, 'supplier_order_id');
    }
}
