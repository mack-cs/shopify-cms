<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class SeoPeriod extends Model
{
    protected $fillable = [
        'label',
        'start_date',
        'end_date',
        'sort_order',
    ];

    protected $casts = [
        'start_date' => 'date',
        'end_date' => 'date',
    ];

    public function metrics(): HasMany
    {
        return $this->hasMany(SeoMetric::class, 'period_id');
    }
}
