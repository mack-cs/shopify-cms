<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ProcurementSupplierOrderLine extends Model
{
    protected $guarded = [];
    protected $casts = [
        'eta_date' => 'date', 'quantity_ordered' => 'integer',
        'completed_at' => 'datetime', 'cancelled_at' => 'datetime',
    ];

    public function order(): BelongsTo { return $this->belongsTo(ProcurementSupplierOrder::class, 'supplier_order_id'); }
    public function variant(): BelongsTo { return $this->belongsTo(Variant::class); }
    public function draft(): BelongsTo { return $this->belongsTo(NewProductDraft::class, 'new_product_draft_id'); }
    public function receipts(): HasMany { return $this->hasMany(ProcurementSupplierReceipt::class, 'supplier_order_line_id'); }

    public function getQuantityReceivedAttribute(): int
    {
        return (int) ($this->getAttribute('receipts_sum_quantity_received')
            ?? $this->receipts()->where('status', 'succeeded')->sum('quantity_received'));
    }

    public function getQuantityOutstandingAttribute(): int
    {
        return max(0, (int) $this->quantity_ordered - $this->quantity_received);
    }

    public function getQuantityStagedAttribute(): int { return (int) $this->receipts()->whereIn('status',['pending','processing'])->sum('quantity_received'); }
    public function getQuantityUncertainAttribute(): int { return (int) $this->receipts()->where('status','manual_review')->sum('quantity_received'); }
    public function getQuantityAvailableToReceiveAttribute(): int
    {
        return $this->quantity_uncertain>0 ? 0 : max(0,$this->quantity_outstanding-$this->quantity_staged);
    }
}
