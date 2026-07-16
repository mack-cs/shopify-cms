<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SeoMetric extends Model
{
    protected $fillable = [
        'period_id',
        'entity_type',
        'entity_value',
        'entity_hash',
        'clicks',
        'impressions',
        'ctr',
        'position',
    ];

    protected $casts = [
        'clicks' => 'integer',
        'impressions' => 'integer',
        'ctr' => 'decimal:2',
        'position' => 'decimal:2',
    ];

    public function period(): BelongsTo
    {
        return $this->belongsTo(SeoPeriod::class, 'period_id');
    }

    public static function hashEntityValue(string $entityValue): string
    {
        return hash('sha256', $entityValue);
    }

    protected static function booted(): void
    {
        static::saving(function (SeoMetric $metric): void {
            $metric->entity_hash = self::hashEntityValue((string) $metric->entity_value);
        });
    }
}
