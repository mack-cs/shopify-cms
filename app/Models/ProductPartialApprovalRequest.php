<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ProductPartialApprovalRequest extends Model
{
    public const STATUS_PENDING = 'pending';
    public const STATUS_APPROVED = 'approved';
    public const STATUS_REJECTED = 'rejected';

    protected $fillable = [
        'product_id',
        'approval_version',
        'requested_by',
        'request_batch_id',
        'target_approver_id',
        'approved_by',
        'status',
        'scopes',
        'core_fields',
        'request_note',
        'approved_at',
    ];

    protected $casts = [
        'scopes' => 'array',
        'core_fields' => 'array',
        'approved_at' => 'datetime',
    ];

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
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
