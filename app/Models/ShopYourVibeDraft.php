<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ShopYourVibeDraft extends Model
{
    protected $guarded = [];

    protected $attributes = ['revision' => 0, 'pending' => false, 'status' => 'synced'];

    protected $casts = [
        'snapshot' => 'array',
        'desired' => 'array',
        'progress' => 'array',
        'remote_jobs' => 'array',
        'pending' => 'boolean',
        'revision' => 'integer',
        'refreshed_at' => 'datetime',
    ];
}
