<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class SearchTrend extends Model
{
    protected $fillable = [
        'period_label',
        'type',
        'label',
        'clicks',
        'impressions',
        'ctr',
        'position',
    ];
}
