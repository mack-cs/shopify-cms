<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ShopifyCollection extends Model
{
    public const SYNC_STATUS_PENDING = 'pending';
    public const SYNC_STATUS_SYNCED = 'synced';

    protected $table = 'collections';

    protected $fillable = [
        'import_id',
        'shopify_id',
        'handle',
        'title',
        'description_html',
        'seo_title',
        'seo_description',
        'deindex',
        'published_on_online_store_only',
        'published_channel_names',
        'batch',
        'sync_status',
        'last_synced_at',
        'approval_version',
        'draft_title',
        'draft_description_html',
        'draft_seo_title',
        'draft_seo_description',
    ];

    protected $casts = [
        'deindex' => 'boolean',
        'published_on_online_store_only' => 'boolean',
        'last_synced_at' => 'datetime',
    ];

    public function import(): BelongsTo
    {
        return $this->belongsTo(Import::class);
    }

    public function approvals(): HasMany
    {
        return $this->hasMany(CollectionApproval::class, 'collection_id');
    }

    public function approvalsForCurrentVersion(): HasMany
    {
        return $this->approvals()->where('approval_version', $this->approval_version);
    }

    public function approvalsForCurrentVersionCount(): int
    {
        return $this->approvalsForCurrentVersion()
            ->distinct('user_id')
            ->count('user_id');
    }

    public function isApprovedByTwo(): bool
    {
        return $this->approvalsForCurrentVersionCount() >= 2;
    }
}
