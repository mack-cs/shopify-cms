<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CollectionApprovalRequest extends Model
{
    public const STATUS_PENDING = 'pending';
    public const STATUS_APPROVED = 'approved';
    public const STATUS_REJECTED = 'rejected';

    protected $fillable = [
        'collection_id',
        'approval_version',
        'requested_by',
        'request_batch_id',
        'target_approver_id',
        'approved_by',
        'status',
        'request_note',
        'approved_at',
    ];

    protected $casts = [
        'approved_at' => 'datetime',
    ];

    public function collection(): BelongsTo
    {
        return $this->belongsTo(ShopifyCollection::class, 'collection_id');
    }

    public function requester(): BelongsTo
    {
        return $this->belongsTo(User::class, 'requested_by');
    }

    public function approver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approved_by');
    }

    public function targetApprover(): BelongsTo
    {
        return $this->belongsTo(User::class, 'target_approver_id');
    }
}
