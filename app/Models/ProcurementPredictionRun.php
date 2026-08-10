<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

final class ProcurementPredictionRun extends Model
{
    public const STATUS_QUEUED = 'queued';
    public const STATUS_RUNNING = 'running';
    public const STATUS_COMPLETED = 'completed';
    public const STATUS_FAILED = 'failed';

    protected $guarded = [];

    protected $casts = [
        'calculation_date' => 'date',
        'started_at' => 'datetime',
        'completed_at' => 'datetime',
        'product_movement_generated_at' => 'datetime',
        'selected_model_information' => 'array',
        'metadata' => 'array',
    ];

    public function movementRun(): BelongsTo
    {
        return $this->belongsTo(ProductMovementReportRun::class, 'product_movement_report_run_id');
    }

    public function requester(): BelongsTo
    {
        return $this->belongsTo(User::class, 'requested_by');
    }

    public function predictions(): HasMany
    {
        return $this->hasMany(ProcurementPrediction::class);
    }
}
