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
        'clicks',
        'impressions',
        'ctr',
        'position',
    ];

    public function period(): BelongsTo
    {
        return $this->belongsTo(SeoPeriod::class, 'period_id');
    }
}
