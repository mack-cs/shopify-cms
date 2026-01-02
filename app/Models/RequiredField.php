<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class RequiredField extends Model
{
    protected $fillable = [
        'scope',
        'source',
        'attribute',
        'label',
        'required',
        'bulk_editable',
    ];

    protected $casts = [
        'required' => 'boolean',
        'bulk_editable' => 'boolean',
    ];
}
