<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

final class ProductLaunchItem extends Model
{
    public const VALIDATION_READY = 'ready';

    public const VALIDATION_WARNING = 'warning';

    public const VALIDATION_BLOCKED = 'blocked';

    public const OUTCOME_PENDING = 'pending';

    public const OUTCOME_LAUNCHED = 'launched';

    public const OUTCOME_SKIPPED = 'skipped';

    public const OUTCOME_BLOCKED = 'blocked';

    public const OUTCOME_FAILED = 'failed';

    protected $guarded = [];

    protected $casts = [
        'current_inventory' => 'integer',
        'proposed_inventory' => 'integer',
        'inventory_write_required' => 'boolean',
        'inventory_written_at' => 'datetime',
        'publication_ids' => 'array',
        'confirmed_publication_ids' => 'array',
        'status_confirmed_at' => 'datetime',
    ];

    public function run(): BelongsTo
    {
        return $this->belongsTo(ProductLaunchRun::class, 'product_launch_run_id');
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }
}
