<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

final class ProductMovementReportRun extends Model
{
    public const STATUS_QUEUED = 'queued';
    public const STATUS_RUNNING = 'running';
    public const STATUS_COMPLETED = 'completed';
    public const STATUS_FAILED = 'failed';

    protected $fillable = [
        'requested_by',
        'calculation_date',
        'analysis_start_date',
        'analysis_end_date',
        'months_analysed',
        'status',
        'settings',
        'row_count',
        'started_at',
        'completed_at',
        'source_data_timestamp',
        'duration_ms',
        'source_version',
        'failure_message',
    ];

    protected $casts = [
        'analysis_start_date' => 'date',
        'analysis_end_date' => 'date',
        'calculation_date' => 'date',
        'months_analysed' => 'decimal:2',
        'settings' => 'array',
        'started_at' => 'datetime',
        'completed_at' => 'datetime',
        'source_data_timestamp' => 'datetime',
    ];

    public function requester(): BelongsTo
    {
        return $this->belongsTo(User::class, 'requested_by');
    }

    public function rows(): HasMany
    {
        return $this->hasMany(ProductMovementReportRow::class);
    }
}
