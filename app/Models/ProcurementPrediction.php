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
