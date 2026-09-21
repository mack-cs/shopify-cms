<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ShopifyImageImportBatch extends Model
{
    public const STATUS_PENDING = 'pending';
    public const STATUS_RUNNING = 'running';
    public const STATUS_COMPLETED = 'completed';
    public const STATUS_FAILED = 'failed';

    protected $fillable = [
        's3_prefix',
        'status',
        'total_files',
        'matched_count',
        'updated_count',
        'failed_count',
        'started_at',
        'completed_at',
        'created_by',
        'error_message',
    ];

    protected $casts = [
        'started_at' => 'datetime',
        'completed_at' => 'datetime',
    ];

    public function items(): HasMany
    {
        return $this->hasMany(ShopifyImageImportItem::class, 'batch_id');
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public static function latestCompletedId(): ?int
    {
        return static::query()
            ->where('status', self::STATUS_COMPLETED)
            ->latest('completed_at')
            ->latest('id')
            ->value('id');
    }
}
