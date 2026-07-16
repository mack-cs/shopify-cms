<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

class ShopifySyncRun extends Model
{
    use HasFactory;

    public const DATASET_ORDERS = 'orders';
    public const DATASET_INVENTORY = 'inventory';

    public const SYNC_TYPE_FULL = 'full';
    public const SYNC_TYPE_DAILY = 'daily';
    public const SYNC_TYPE_SNAPSHOT = 'snapshot';
    public const SYNC_TYPE_HISTORICAL_RANGE = 'historical_range';

    public const RUN_MODE_SCHEDULED = 'scheduled';
    public const RUN_MODE_MANUAL = 'manual';
    public const RUN_MODE_BACKFILL = 'backfill';

    public const STATUS_PENDING = 'pending';
    public const STATUS_STARTING = 'starting';
    public const STATUS_RUNNING = 'running';
    public const STATUS_DOWNLOADING = 'downloading';
    public const STATUS_PROCESSING = 'processing';
    public const STATUS_COMPLETED = 'completed';
    public const STATUS_FAILED = 'failed';
    public const STATUS_CANCELLED = 'cancelled';

    protected $fillable = [
        'uuid',
        'dataset',
        'sync_type',
        'run_mode',
        'business_date',
        'business_timezone',
        'window_start',
        'window_end',
        'lookback_days',
        'shopify_operation_id',
        'shopify_operation_status',
        'status',
        'raw_s3_bucket',
        'raw_s3_key',
        'metadata_s3_key',
        'root_object_count',
        'object_count',
        'file_size',
        'records_processed',
        'orders_processed',
        'order_items_processed',
        'refunds_processed',
        'discounts_processed',
        'inventory_items_processed',
        'inventory_levels_processed',
        'poll_attempts',
        'started_at',
        'shopify_completed_at',
        'processing_started_at',
        'completed_at',
        'failed_at',
        'error_message',
        'metadata',
    ];

    protected $casts = [
        'business_date' => 'date',
        'window_start' => 'datetime',
        'window_end' => 'datetime',
        'started_at' => 'datetime',
        'shopify_completed_at' => 'datetime',
        'processing_started_at' => 'datetime',
        'completed_at' => 'datetime',
        'failed_at' => 'datetime',
        'metadata' => 'array',
    ];

    protected static function booted(): void
    {
        static::creating(function (ShopifySyncRun $run): void {
            if (blank($run->uuid)) {
                $run->uuid = (string) Str::uuid();
            }
        });
    }

    public function orders(): HasMany
    {
        return $this->hasMany(ShopifyOrder::class, 'latest_sync_run_id');
    }

    public function orderItems(): HasMany
    {
        return $this->hasMany(ShopifyOrderItem::class, 'latest_sync_run_id');
    }

    public function inventorySnapshots(): HasMany
    {
        return $this->hasMany(ShopifyInventorySnapshot::class, 'sync_run_id');
    }

    public function issues(): HasMany
    {
        return $this->hasMany(ShopifySyncIssue::class, 'sync_run_id');
    }

    public function fail(string $message, ?array $metadata = null): void
    {
        $existing = $this->metadata ?? [];

        $this->forceFill([
            'status' => self::STATUS_FAILED,
            'failed_at' => now(),
            'error_message' => $message,
            'metadata' => $metadata === null ? $existing : array_merge($existing, $metadata),
        ])->save();
    }

    public function durationSeconds(): ?int
    {
        if (!$this->started_at instanceof Carbon) {
            return null;
        }

        $endedAt = $this->completed_at ?? $this->failed_at ?? now();

        return $this->started_at->diffInSeconds($endedAt);
    }
}
