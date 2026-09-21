<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

final class ShopifyCollectionProductReportRow extends Model
{
    protected $guarded = [];

    protected $casts = [
        'collection_published_online' => 'boolean',
        'collection_online_publish_date' => 'datetime',
        'collection_updated_at' => 'datetime',
        'collection_publications' => 'array',
        'product_created_at' => 'datetime',
        'product_updated_at' => 'datetime',
        'product_published_at' => 'datetime',
        'tags' => 'array',
        'variants' => 'array',
    ];

    public function run(): BelongsTo
    {
        return $this->belongsTo(ShopifyCollectionProductReportRun::class, 'shopify_collection_product_report_run_id');
    }

    public function getMainCollectionAttribute(): ?string
    {
        return app(\App\Services\DropdownCollectionCatalog::class)
            ->collectionForTags((array) ($this->tags ?? []));
    }
}
