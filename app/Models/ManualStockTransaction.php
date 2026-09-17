<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ManualStockTransaction extends Model
{
    public const TYPES = ['direct_order' => 'Direct Order', 'complimentary' => 'Complimentary / Given Away', 'internal_use' => 'Internal Use', 'other' => 'Other'];
    public const MODE_PROCESS = 'process_inventory';
    public const MODE_HISTORICAL = 'historical';
    protected $guarded = [];
    protected $casts = ['transaction_date' => 'date', 'is_historical' => 'boolean', 'processed_at' => 'datetime'];
    protected static function booted(): void
    {
        static::creating(function (self $record): void {
            $record->created_by ??= auth()->id();
            $record->is_historical = $record->processing_mode === self::MODE_HISTORICAL;
        });
        static::saving(function (self $record): void {
            $record->is_historical = $record->processing_mode === self::MODE_HISTORICAL;
        });
    }
    public function items(): HasMany { return $this->hasMany(ManualStockTransactionItem::class); }
    public function impacts(): HasMany { return $this->hasMany(ManualStockTransactionImpact::class); }
    public function creator(): BelongsTo { return $this->belongsTo(User::class, 'created_by'); }
    public function processor(): BelongsTo { return $this->belongsTo(User::class, 'processed_by'); }
}
