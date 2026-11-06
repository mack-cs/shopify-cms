<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SyvCollectionSortEvent extends Model
{
    public const UPDATED_AT = null;

    protected $guarded = [];

    protected $casts = [
        'revision' => 'integer',
        'from_position' => 'integer',
        'to_position' => 'integer',
        'created_at' => 'datetime',
    ];

    public function state(): BelongsTo
    {
        return $this->belongsTo(SyvCollectionSortState::class, 'sort_state_id');
    }

    public function actor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'actor_id');
    }
}
