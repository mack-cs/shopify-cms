<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ScheduledJobItem extends Model
{
    public const STATUS_PENDING = 'pending';
    public const STATUS_RUNNING = 'running';
    public const STATUS_COMPLETED = 'completed';
    public const STATUS_FAILED = 'failed';
    public const STATUS_SKIPPED = 'skipped';

    protected $fillable = [
        'scheduled_job_id',
        'product_id',
        'sale_product_update_id',
        'sku',
        'status',
        'payload',
        'response',
        'error_message',
        'started_at',
        'completed_at',
    ];

    protected $casts = [
        'payload' => 'array',
        'response' => 'array',
        'started_at' => 'datetime',
        'completed_at' => 'datetime',
    ];

    public function scheduledJob(): BelongsTo
    {
        return $this->belongsTo(ScheduledJob::class);
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    public function saleProductUpdate(): BelongsTo
    {
        return $this->belongsTo(SaleProductUpdate::class);
    }
}
