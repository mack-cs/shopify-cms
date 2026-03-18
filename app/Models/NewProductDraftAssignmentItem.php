<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class NewProductDraftAssignmentItem extends Model
{
    protected $fillable = [
        'assignment_id',
        'new_product_draft_id',
        'handle',
        'title',
    ];

    public function assignment(): BelongsTo
    {
        return $this->belongsTo(NewProductDraftAssignment::class, 'assignment_id');
    }

    public function draft(): BelongsTo
    {
        return $this->belongsTo(NewProductDraft::class, 'new_product_draft_id');
    }
}
