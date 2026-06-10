<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

class SiteAuditUrl extends Model
{
    public const RESOURCE_PRODUCT = 'product';
    public const RESOURCE_COLLECTION = 'collection';
    public const RESOURCE_PAGE = 'page';
    public const RESOURCE_BLOG = 'blog';
    public const RESOURCE_UNKNOWN = 'unknown';

    protected $fillable = [
        'url',
        'source',
        'sitemap_url',
        'resource_type',
        'is_active',
        'last_seen_at',
        'last_checked_at',
    ];

    protected $casts = [
        'is_active' => 'boolean',
        'last_seen_at' => 'datetime',
        'last_checked_at' => 'datetime',
    ];

    public function results(): HasMany
    {
        return $this->hasMany(SiteAuditResult::class);
    }

    public function latestResult(): HasOne
    {
        return $this->hasOne(SiteAuditResult::class)->latestOfMany();
    }
}
