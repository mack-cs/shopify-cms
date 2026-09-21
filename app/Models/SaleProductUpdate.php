<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SaleProductUpdate extends Model
{
    public const STATUS_PENDING = 'pending';
    public const STATUS_APPROVED = 'approved';
    public const STATUS_SCHEDULED = 'scheduled';
    public const STATUS_RUNNING = 'running';
    public const STATUS_COMPLETED = 'completed';
    public const STATUS_FAILED = 'failed';
    public const STATUS_CANCELLED = 'cancelled';

    protected $fillable = [
        'sale_import_batch_id',
        'scheduled_job_id',
        'product_id',
        'variant_id',
        'sku',
        'status',
        'current_price',
        'imported_old_price',
        'sale_price',
        'compare_at_price',
        'existing_tags',
        'prepared_tags',
        'approved_at',
        'approved_by',
        'scheduled_at',
        'pushed_at',
        'metadata',
        'error_message',
    ];

    protected $casts = [
        'current_price' => 'decimal:2',
        'imported_old_price' => 'decimal:2',
        'sale_price' => 'decimal:2',
        'compare_at_price' => 'decimal:2',
        'approved_at' => 'datetime',
        'scheduled_at' => 'datetime',
        'pushed_at' => 'datetime',
        'metadata' => 'array',
    ];

    public function batch(): BelongsTo
    {
        return $this->belongsTo(SaleImportBatch::class, 'sale_import_batch_id');
    }

    public function scheduledJob(): BelongsTo
    {
        return $this->belongsTo(ScheduledJob::class);
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    public function variant(): BelongsTo
    {
        return $this->belongsTo(Variant::class);
    }

    public function approver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approved_by');
    }

    public function scopeOpen(Builder $query): Builder
    {
        return $query->whereIn('status', [
            self::STATUS_PENDING,
            self::STATUS_APPROVED,
            self::STATUS_SCHEDULED,
            self::STATUS_RUNNING,
        ]);
    }

    public function scopeApprovedForScheduling(Builder $query): Builder
    {
        return $query->where('status', self::STATUS_APPROVED);
    }
}
