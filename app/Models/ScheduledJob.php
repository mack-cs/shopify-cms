<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ScheduledJob extends Model
{
    public const TYPE_SALE_PRODUCT_UPDATE = 'sale_product_update';

    public const STATUS_DRAFT = 'draft';
    public const STATUS_SCHEDULED = 'scheduled';
    public const STATUS_RUNNING = 'running';
    public const STATUS_COMPLETED = 'completed';
    public const STATUS_FAILED = 'failed';
    public const STATUS_CANCELLED = 'cancelled';

    protected $fillable = [
        'type',
        'status',
        'scheduled_at',
        'started_at',
        'completed_at',
        'created_by',
        'metadata',
        'error_summary',
        'total_items',
        'succeeded_items',
        'failed_items',
    ];

    protected $casts = [
        'scheduled_at' => 'datetime',
        'started_at' => 'datetime',
        'completed_at' => 'datetime',
        'metadata' => 'array',
    ];

    public function items(): HasMany
    {
        return $this->hasMany(ScheduledJobItem::class);
    }

    public function saleProductUpdates(): HasMany
    {
        return $this->hasMany(SaleProductUpdate::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
