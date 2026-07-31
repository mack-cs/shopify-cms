<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

final class ProductMovementReportRow extends Model
{
    protected $guarded = [];

    protected $casts = [
        'analysis_start_date' => 'date',
        'analysis_end_date' => 'date',
        'product_created_at' => 'datetime',
        'first_sale_date' => 'date',
        'last_sale_date' => 'date',
        'first_inventory_snapshot_date' => 'date',
        'inventory_tracked' => 'boolean',
        'currently_on_sale' => 'boolean',
        'has_snapshot_history' => 'boolean',
        'months_analysed' => 'decimal:2',
        'average_units_per_month' => 'decimal:4',
        'average_units_per_30_days' => 'decimal:4',
        'sales_consistency_percentage' => 'decimal:2',
        'units_sold_per_30_in_stock_days' => 'decimal:4',
        'average_snapshot_inventory' => 'decimal:4',
        'movement_score' => 'decimal:2',
        'current_price' => 'decimal:2',
        'compare_at_price' => 'decimal:2',
        'discount_percentage' => 'decimal:2',
    ];

    public function run(): BelongsTo
    {
        return $this->belongsTo(ProductMovementReportRun::class, 'product_movement_report_run_id');
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    public function variant(): BelongsTo
    {
        return $this->belongsTo(Variant::class);
    }
}
