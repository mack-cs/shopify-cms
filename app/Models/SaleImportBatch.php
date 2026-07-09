<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class SaleImportBatch extends Model
{
    public const STATUS_COMPLETED = 'completed';
    public const STATUS_FAILED = 'failed';

    protected $fillable = [
        'filename',
        'status',
        'total_rows',
        'matched_count',
        'unmatched_count',
        'failed_count',
        'created_by',
        'error_message',
    ];

    public function items(): HasMany
    {
        return $this->hasMany(SaleImportItem::class);
    }

    public function saleProductUpdates(): HasMany
    {
        return $this->hasMany(SaleProductUpdate::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public static function latestId(): ?int
    {
        return static::query()
            ->latest('created_at')
            ->latest('id')
            ->value('id');
    }
}
