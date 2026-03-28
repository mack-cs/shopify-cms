<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

class Import extends Model
{
    protected $fillable = [
        'filename', 'mode', 'status', 'created_by', 'headers', 'is_current', 'is_valid',
    ];

    protected $casts = [
        'headers' => 'array',
        'is_current' => 'boolean',
        'is_valid' => 'boolean',
    ];

    public function rows(): HasMany
    {
        return $this->hasMany(ShopifyRow::class);
    }

    public function products(): HasMany
    {
        return $this->hasMany(Product::class);
    }

    public function metafields(): HasMany
    {
        return $this->hasMany(ShopifyMetafield::class);
    }

    public function collections(): HasMany
    {
        return $this->hasMany(ShopifyCollection::class);
    }

    public function syncSnapshot(): HasOne
    {
        return $this->hasOne(ShopifySyncSnapshot::class);
    }
}
