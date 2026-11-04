<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class SyvCollectionSortState extends Model
{
    public const NOT_SORTED = 'not_sorted';

    public const AWAITING = 'awaiting_approval';

    public const PUBLISHED = 'approved_published';

    protected $guarded = [];

    protected $casts = [
        'explicit' => 'boolean',
        'highlight' => 'boolean',
        'from_position' => 'integer',
        'to_position' => 'integer',
        'sorted_at' => 'datetime',
        'submitted_at' => 'datetime',
        'submitted_revision' => 'integer',
        'approved_at' => 'datetime',
        'approved_revision' => 'integer',
        'shopify_confirmed_at' => 'datetime',
    ];

    public function draft(): BelongsTo
    {
        return $this->belongsTo(ShopYourVibeDraft::class, 'draft_id');
    }

    public function events(): HasMany
    {
        return $this->hasMany(SyvCollectionSortEvent::class, 'sort_state_id');
    }

    public static function label(?string $status): string
    {
        return match ($status) {
            self::AWAITING => 'Awaiting approval',
            self::PUBLISHED => 'Approved & published',
            default => 'Not sorted',
        };
    }
}
