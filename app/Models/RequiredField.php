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
    ];

    protected $casts = [
        'required' => 'boolean',
    ];
}
