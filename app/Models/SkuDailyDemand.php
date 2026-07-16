<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Factories\HasFactory;

class SkuDailyDemand extends Model
{
    use HasFactory;

    protected $table = 'sku_daily_demand';

    protected $fillable = [
        'sku',
        'demand_date',
        'gross_units',
        'cancelled_units',
        'refunded_units',
        'net_units',
        'order_count',
        'gross_revenue',
        'discount_amount',
        'net_revenue',
        'calculated_at',
    ];

    protected $casts = [
        'demand_date' => 'date',
        'gross_units' => 'integer',
        'cancelled_units' => 'integer',
        'refunded_units' => 'integer',
        'net_units' => 'integer',
        'order_count' => 'integer',
        'gross_revenue' => 'decimal:2',
        'discount_amount' => 'decimal:2',
        'net_revenue' => 'decimal:2',
        'calculated_at' => 'datetime',
    ];
}
