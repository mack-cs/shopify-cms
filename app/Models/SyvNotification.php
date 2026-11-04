<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SyvNotification extends Model
{
    public const TYPE_SORT_APPROVAL = 'sort_approval';

    public const TYPE_LAUNCH_WARNING = 'launch_sort_warning';

    public const PENDING = 'pending';

    public const SENT = 'sent';

    public const SUPPRESSED = 'suppressed';

    public const FAILED = 'failed';

    protected $guarded = [];

    protected $casts = [
        'payload' => 'array',
        'attempts' => 'integer',
        'sent_at' => 'datetime',
    ];

    public function draft(): BelongsTo
    {
        return $this->belongsTo(ShopYourVibeDraft::class, 'draft_id');
    }

    public function launchItem(): BelongsTo
    {
        return $this->belongsTo(ProductLaunchItem::class, 'product_launch_item_id');
    }
}
