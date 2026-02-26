<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Category extends Model
{
    protected $fillable = [
        'name',
        'google_product_category',
        'shopify_taxonomy_gid',
        'active',
    ];

    protected $casts = [
        'active' => 'boolean',
    ];
}
