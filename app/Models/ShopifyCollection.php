<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Illuminate\Support\Facades\Schema;

class ShopifyCollection extends Model
{
    protected static ?bool $supportsShopifySyncWarningsColumnCache = null;

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
        'footer_title',
        'elegant_footer_description',
        'deindex',
        'published_on_online_store_only',
        'published_channel_names',
        'batch',
        'sync_status',
        'last_synced_at',
        'approval_version',
        'draft_handle',
        'draft_title',
        'draft_description_html',
        'draft_seo_title',
        'draft_seo_description',
        'draft_footer_title',
        'draft_elegant_footer_description',
        'shopify_sync_warnings',
    ];

    protected $casts = [
        'deindex' => 'boolean',
        'published_on_online_store_only' => 'boolean',
        'last_synced_at' => 'datetime',
        'shopify_sync_warnings' => 'array',
    ];

    public function import(): BelongsTo
    {
        return $this->belongsTo(Import::class);
    }

    public function approvals(): HasMany
    {
        return $this->hasMany(CollectionApproval::class, 'collection_id');
    }

    public function urlRedirects(): HasMany
    {
        return $this->hasMany(CollectionUrlRedirect::class, 'collection_id');
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

    public function deletionRequests(): MorphMany
    {
        return $this->morphMany(DeletionRequest::class, 'deletable');
    }

    public function isApprovedByTwo(): bool
    {
        return $this->approvalsForCurrentVersionCount() >= 2;
    }

    public static function supportsShopifySyncWarningsColumn(): bool
    {
        if (self::$supportsShopifySyncWarningsColumnCache !== null) {
            return self::$supportsShopifySyncWarningsColumnCache;
        }

        return self::$supportsShopifySyncWarningsColumnCache = Schema::hasColumn(
            (new static())->getTable(),
            'shopify_sync_warnings'
        );
    }

    public function shopifySyncWarningCount(): int
    {
        return count($this->shopifySyncWarnings());
    }

    /**
     * @return array<int, array{field:string,label:string,draft_value:string,shopify_value:string}>
     */
    public function shopifySyncWarnings(): array
    {
        if (!static::supportsShopifySyncWarningsColumn()) {
            return [];
        }

        $warnings = $this->shopify_sync_warnings;

        if (!is_array($warnings)) {
            return [];
        }

        return array_values(array_filter($warnings, function (mixed $warning): bool {
            $field = is_array($warning) ? trim((string) ($warning['field'] ?? '')) : '';

            return is_array($warning)
                && $field !== ''
                && is_string($warning['label'] ?? null)
                && array_key_exists('draft_value', $warning)
                && array_key_exists('shopify_value', $warning);
        }));
    }
}
