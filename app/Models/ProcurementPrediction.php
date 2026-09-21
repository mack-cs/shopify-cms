<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

final class ProcurementPrediction extends Model
{
    protected $guarded = [];

    protected $casts = [
        'movement_classification_matches' => 'boolean',
        'currently_on_sale' => 'boolean',
        'sale_percentage' => 'decimal:2',
        'ignore' => 'boolean',
        'incoming_stock_covers_requirement' => 'boolean',
        'stockout_before_incoming_arrival' => 'boolean',
        'procurement_actioned' => 'boolean',
        'quantity_on_order_phase_1' => 'integer',
        'quantity_on_order_phase_2' => 'integer',
        'quantity_on_order_phase_3' => 'integer',
        'confirmed_quantity_on_order_phase_1' => 'integer',
        'confirmed_quantity_on_order_phase_2' => 'integer',
        'confirmed_quantity_on_order_phase_3' => 'integer',
        'total_quantity_on_order' => 'integer',
        'total_confirmed_quantity_on_order' => 'integer',
        'eta_date_phase_1' => 'date:Y-m-d',
        'eta_date_phase_2' => 'date:Y-m-d',
        'eta_date_phase_3' => 'date:Y-m-d',
        'predicted_runout_date' => 'date',
        'recommended_order_by_date' => 'date',
        'generated_at' => 'datetime',
        'movement_score' => 'decimal:2',
        'sales_consistency' => 'decimal:2',
        'average_weekly_demand' => 'decimal:4',
        'units_sold_per_30_in_stock_days' => 'decimal:4',
        'ml_predicted_weekly_demand' => 'decimal:4',
        'weighted_predicted_weekly_demand' => 'decimal:4',
        'predicted_weekly_demand' => 'decimal:4',
        'estimated_days_of_stock_remaining' => 'decimal:2',
    ];

    public function run(): BelongsTo
    {
        return $this->belongsTo(ProcurementPredictionRun::class, 'procurement_prediction_run_id');
    }
}
