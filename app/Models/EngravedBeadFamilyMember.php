<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

final class EngravedBeadFamilyMember extends Model
{
    protected $guarded = [];

    protected $casts = [
        'required_beads' => 'integer', 'disable_threshold' => 'integer',
        'last_verified_tracked' => 'boolean', 'last_verified_sellable' => 'boolean',
        'automation_disabled' => 'boolean',
        'manual_disabled' => 'boolean',
    ];

    public function family(): BelongsTo
    {
        return $this->belongsTo(EngravedBeadFamily::class, 'family_id');
    }
}
