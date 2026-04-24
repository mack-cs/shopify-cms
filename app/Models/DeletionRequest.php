<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphTo;

class DeletionRequest extends Model
{
    public const STATUS_PENDING = 'pending';
    public const STATUS_PROCESSING = 'processing';
    public const STATUS_COMPLETED = 'completed';
    public const STATUS_FAILED = 'failed';
    public const STATUS_REJECTED = 'rejected';

    protected $fillable = [
        'deletable_type',
        'deletable_id',
        'import_id',
        'requested_by',
        'completed_by',
        'rejected_by',
        'entity_type',
        'entity_title',
        'entity_handle',
        'shopify_id',
        'reason',
        'rejection_reason',
        'status',
        'completed_at',
        'rejected_at',
        'failure_message',
    ];

    protected $casts = [
        'completed_at' => 'datetime',
        'rejected_at' => 'datetime',
    ];

    public function deletable(): MorphTo
    {
        return $this->morphTo();
    }

    public function import(): BelongsTo
    {
        return $this->belongsTo(Import::class);
    }

    public function requester(): BelongsTo
    {
        return $this->belongsTo(User::class, 'requested_by');
    }

    public function completedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'completed_by');
    }

    public function rejectedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'rejected_by');
    }

    public function approvals(): HasMany
    {
        return $this->hasMany(DeletionRequestApproval::class);
    }

    public function approvalCount(): int
    {
        return $this->approvals()->distinct('user_id')->count('user_id');
    }

    public function isApprovedByTwo(): bool
    {
        return $this->approvalCount() >= 2;
    }

    public function userHasApproved(?int $userId): bool
    {
        if (!$userId) {
            return false;
        }

        return $this->approvals()->where('user_id', $userId)->exists();
    }
}
