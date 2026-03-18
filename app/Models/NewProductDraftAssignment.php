<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class NewProductDraftAssignment extends Model
{
    protected $fillable = [
        'sent_by',
        'status',
        'from_name',
        'from_email',
        'to_emails',
        'cc_emails',
        'subject',
        'body',
        'context_columns',
        'selected_columns',
        'csv_disk',
        'csv_path',
        'sent_at',
        'error_message',
    ];

    protected $casts = [
        'to_emails' => 'array',
        'cc_emails' => 'array',
        'context_columns' => 'array',
        'selected_columns' => 'array',
        'sent_at' => 'datetime',
    ];

    public function sender(): BelongsTo
    {
        return $this->belongsTo(User::class, 'sent_by');
    }

    public function items(): HasMany
    {
        return $this->hasMany(NewProductDraftAssignmentItem::class, 'assignment_id');
    }

    public function logs(): HasMany
    {
        return $this->hasMany(NewProductDraftAssignmentLog::class, 'assignment_id');
    }
}
