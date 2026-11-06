<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SyvVerifiedMembership extends Model
{
    protected $guarded = [];

    protected $casts = [
        'first_verified_at' => 'datetime',
        'last_verified_at' => 'datetime',
    ];

    public function draft(): BelongsTo
    {
        return $this->belongsTo(ShopYourVibeDraft::class, 'draft_id');
    }
}
