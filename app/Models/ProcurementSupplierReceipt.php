<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOneThrough;

class ProcurementSupplierReceipt extends Model
{
    protected $guarded = [];
    protected $casts = [
        'quantity_received' => 'integer', 'received_at' => 'datetime',
        'inventory_before' => 'integer', 'inventory_after' => 'integer',
        'shopify_adjustment_started_at' => 'datetime',
        'shopify_adjusted_at' => 'datetime', 'processed_at' => 'datetime',
        'out_of_sequence_confirmed_at' => 'datetime',
        'out_of_sequence_audit' => 'array',
        'pending_push_reminded_at' => 'datetime',
    ];

    public function line(): BelongsTo { return $this->belongsTo(ProcurementSupplierOrderLine::class, 'supplier_order_line_id'); }
    public function batch(): BelongsTo { return $this->belongsTo(ProcurementSupplierImportBatch::class, 'import_batch_id'); }
    public function order(): HasOneThrough
    {
        return $this->hasOneThrough(
            ProcurementSupplierOrder::class,
            ProcurementSupplierOrderLine::class,
            'id',
            'id',
            'supplier_order_line_id',
            'supplier_order_id',
        );
    }
    public function createdBy(): BelongsTo { return $this->belongsTo(User::class, 'created_by'); }
}
