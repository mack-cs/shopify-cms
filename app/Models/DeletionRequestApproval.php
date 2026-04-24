<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class DeletionRequestApproval extends Model
{
    protected $fillable = [
        'deletion_request_id',
        'user_id',
    ];

    public function deletionRequest(): BelongsTo
    {
        return $this->belongsTo(DeletionRequest::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
