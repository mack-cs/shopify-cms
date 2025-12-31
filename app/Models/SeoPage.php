<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class SeoPage extends Model
{
    protected $fillable = [
        'name',
        'keywords',
        'url',
        'seo_title',
        'meta_title',
        'meta_description',
        'notes',
    ];
}
