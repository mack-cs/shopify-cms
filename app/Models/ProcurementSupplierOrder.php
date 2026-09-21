<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasManyThrough;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ProcurementSupplierOrder extends Model
{
    protected $guarded = [];

    public function lines(): HasMany
    {
        return $this->hasMany(ProcurementSupplierOrderLine::class, 'supplier_order_id');
    }

    public function amendments(): HasMany
    {
        return $this->hasMany(ProcurementSupplierOrderAmendment::class, 'supplier_order_id');
    }

    public function receipts(): HasManyThrough
    {
        return $this->hasManyThrough(
            ProcurementSupplierReceipt::class,
            ProcurementSupplierOrderLine::class,
            'supplier_order_id',
            'supplier_order_line_id',
            'id',
            'id',
        );
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
