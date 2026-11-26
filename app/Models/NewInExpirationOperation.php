<?php

namespace App\Models;

use App\Casts\UtcDateTimeCast;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

class NewInExpirationOperation extends Model
{
    public const STATUS_PENDING = 'pending';

    public const STATUS_MUTATING = 'mutating';

    public const STATUS_REMOTE_CONFIRMED = 'remote_confirmed';

    public const STATUS_CONFIRMED = 'confirmed';

    public const STATUS_NEEDS_REVIEW = 'needs_review';

    public const STATUS_FAILED = 'failed';

    public const STATUS_SUPERSEDED = 'superseded';

    public $incrementing = false;

    protected $keyType = 'string';

    protected $guarded = [];

    protected $casts = [
        'tags_to_remove' => 'array',
        'before_tags' => 'array',
        'meta' => 'array',
        'shopify_created_at' => UtcDateTimeCast::class,
        'available_at' => 'datetime',
        'mutating_at' => 'datetime',
        'remote_confirmed_at' => 'datetime',
        'confirmed_at' => 'datetime',
        'attempts' => 'integer',
    ];

    protected static function booted(): void
    {
        static::creating(function (self $operation): void {
            if (! filled($operation->id)) {
                $operation->id = (string) Str::uuid();
            }
        });
    }

    public function isOpen(): bool
    {
        return in_array($this->status, [
            self::STATUS_PENDING,
            self::STATUS_MUTATING,
            self::STATUS_REMOTE_CONFIRMED,
            self::STATUS_NEEDS_REVIEW,
        ], true);
    }
}
