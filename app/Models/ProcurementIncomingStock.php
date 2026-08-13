<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

final class ProcurementIncomingStock extends Model
{
    protected $guarded = [];

    protected $casts = [
        'quantity_on_order_phase_1' => 'integer',
        'quantity_on_order_phase_2' => 'integer',
        'quantity_on_order_phase_3' => 'integer',
        'total_quantity_on_order' => 'integer',
        'detected_at' => 'datetime',
        'input_changed_at' => 'datetime',
    ];

    public function variant(): BelongsTo
    {
        return $this->belongsTo(Variant::class);
    }

    public function lastPredictionRun(): BelongsTo
    {
        return $this->belongsTo(ProcurementPredictionRun::class, 'last_prediction_run_id');
    }

    public function changes(): HasMany
    {
        return $this->hasMany(ProcurementIncomingStockChange::class);
    }

    public function isStaleFor(?ProcurementPredictionRun $run): bool
    {
        if ($run === null || $this->last_prediction_run_id !== $run->id) {
            return true;
        }

        return $this->input_changed_at !== null
            && ($run->incoming_stock_snapshot_at === null
                || $this->input_changed_at->gt($run->incoming_stock_snapshot_at));
    }
}
