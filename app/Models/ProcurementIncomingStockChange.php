<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

final class ProcurementIncomingStockChange extends Model
{
    protected $guarded = [];

    protected $casts = [
        'detected_at' => 'datetime',
        'metadata' => 'array',
    ];

    public function incomingStock(): BelongsTo
    {
        return $this->belongsTo(ProcurementIncomingStock::class, 'procurement_incoming_stock_id');
    }
}
