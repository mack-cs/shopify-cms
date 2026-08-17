<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ProcurementSupplierReceipt extends Model
{
    protected $guarded = [];
    protected $casts = [
        'quantity_received' => 'integer', 'shopify_adjustment_started_at' => 'datetime',
        'shopify_adjusted_at' => 'datetime', 'processed_at' => 'datetime',
    ];

    public function line(): BelongsTo { return $this->belongsTo(ProcurementSupplierOrderLine::class, 'supplier_order_line_id'); }
    public function batch(): BelongsTo { return $this->belongsTo(ProcurementSupplierImportBatch::class, 'import_batch_id'); }
}
