<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ProcurementSupplierOrderAmendment extends Model
{
    protected $guarded = [];

    public function order(): BelongsTo
    {
        return $this->belongsTo(ProcurementSupplierOrder::class, 'supplier_order_id');
    }

    public function line(): BelongsTo
    {
        return $this->belongsTo(ProcurementSupplierOrderLine::class, 'supplier_order_line_id');
    }

    public function amendedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'amended_by');
    }
}
