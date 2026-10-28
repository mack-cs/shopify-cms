<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

final class EngravedBeadFamilyAudit extends Model
{
    protected $guarded = [];

    protected $casts = ['details' => 'array'];
}
