<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

final class ProcurementPredictionInput extends Model
{
    protected $guarded = [];

    protected $casts = [
        'quantity_on_order_phase_1' => 'integer',
        'quantity_on_order_phase_2' => 'integer',
        'quantity_on_order_phase_3' => 'integer',
        'total_quantity_on_order' => 'integer',
        'source_changed_at' => 'datetime',
    ];

    public function run(): BelongsTo
    {
        return $this->belongsTo(ProcurementPredictionRun::class, 'procurement_prediction_run_id');
    }

    public function variant(): BelongsTo
    {
        return $this->belongsTo(Variant::class);
    }
}
