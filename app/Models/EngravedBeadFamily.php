<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

final class EngravedBeadFamily extends Model
{
    protected $guarded = [];

    protected $casts = [
        'validation_errors' => 'array', 'observed_mapping' => 'array',
        'validated_at' => 'datetime', 'activated_at' => 'datetime',
    ];

    public function members(): HasMany
    {
        return $this->hasMany(EngravedBeadFamilyMember::class, 'family_id')->orderBy('required_beads');
    }

    public function audits(): HasMany
    {
        return $this->hasMany(EngravedBeadFamilyAudit::class, 'family_id');
    }
}
