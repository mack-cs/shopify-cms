<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class NewProductDraftAssignmentLog extends Model
{
    protected $fillable = [
        'assignment_id',
        'user_id',
        'action',
        'message',
        'meta',
    ];

    protected $casts = [
        'meta' => 'array',
    ];

    public function assignment(): BelongsTo
    {
        return $this->belongsTo(NewProductDraftAssignment::class, 'assignment_id');
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
