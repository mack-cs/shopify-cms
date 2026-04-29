<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use App\Services\CategoryTypeMap;
use App\Services\HeaderStore;
use App\Models\StyleProfile;
use App\Models\Product;

class NewProductDraft extends Model
{
    protected static ?bool $supportsShopifySyncWarningsColumnCache = null;

    public const ORIGIN_DRAFT_TOOL = 'draft_tool';
    public const ORIGIN_SHOPIFY_SEED = 'shopify_seed';
    public const ORIGIN_PRODUCT_MIRROR = 'product_mirror';
    public const SHOPIFY_MISSING_PENDING_REVIEW = 'pending_review';
    public const SHOPIFY_MISSING_INVESTIGATING = 'investigating';
    public const SHOPIFY_MISSING_CLEANED = 'cleaned';
    public const SHOPIFY_MISSING_RECOVERY_ENABLED = 'recovery_enabled';

    protected $fillable = [
        'handle',
        'shopify_id',
        'sku',
        'title',
        'body_html',
        'vendor',
        'product_category',
        'google_product_category',
        'type',
        'tags',
        'color_string',
        'status',
        'published',
        'image_path',
        'image_url',
        'batch',
        'variant_price',
        'variant_compare_at_price',
        'variant_inventory_qty',
        'material_cost',
        'jewelry_material',
        'product_materials',
        'materials_and_dimensions',
        'product_design',
        'metal',
        'colour_style',
        'size',
        'siblings',
        'siblings_collection_name',
        'sibling_collection',
        'uvp_short_paragraph',
        'complementary_products',
        'seo_deindex',
        'variant_inventory_policy',
        'variant_fulfillment_service',
        'payload',
        'origin',
        'shopify_sync_warnings',
        'shopify_missing_detected_at',
        'shopify_missing_status',
        'shopify_missing_sync_blocked',
        'approval_version',
        'created_by',
    ];

    protected $casts = [
        'payload' => 'array',
        'shopify_sync_warnings' => 'array',
        'shopify_missing_detected_at' => 'datetime',
        'shopify_missing_sync_blocked' => 'boolean',
        'variant_price' => 'decimal:2',
        'variant_compare_at_price' => 'decimal:2',
        'variant_inventory_qty' => 'integer',
        'material_cost' => 'decimal:2',
    ];

    protected static function booted(): void
    {
        static::saving(function (NewProductDraft $draft): void {
            $current = is_string($draft->google_product_category)
                ? trim($draft->google_product_category)
                : '';
            if ($current !== '') {
                return;
            }

            $resolved = CategoryTypeMap::resolve(
                is_string($draft->product_category) ? $draft->product_category : null,
                is_string($draft->type) ? $draft->type : null,
                null
            );

            $google = trim((string) ($resolved['google_product_category'] ?? ''));
            if ($google !== '') {
                $draft->google_product_category = $google;
            }
        });
    }

    public function getGoogleProductCategoryAttribute($value): ?string
    {
        $current = is_string($value) ? trim($value) : '';
        if ($current !== '') {
            return $current;
        }

        $resolved = CategoryTypeMap::resolve(
            is_string($this->attributes['product_category'] ?? null) ? $this->attributes['product_category'] : null,
            is_string($this->attributes['type'] ?? null) ? $this->attributes['type'] : null,
            null
        );

        $google = trim((string) ($resolved['google_product_category'] ?? ''));
        return $google === '' ? null : $google;
    }

    public function imageUrl(): ?string
    {
        $imageUrl = is_string($this->image_url) ? trim($this->image_url) : '';
        if ($imageUrl !== '') {
            return $imageUrl;
        }

        $imagePath = is_string($this->image_path) ? trim($this->image_path) : '';
        if ($imagePath !== '') {
            if (str_starts_with($imagePath, 'http://') || str_starts_with($imagePath, 'https://')) {
                return $imagePath;
            }

            return Storage::disk('public')->url($imagePath);
        }

        return null;
    }

    public function setImageUrlAttribute(mixed $value): void
    {
        $this->attributes['image_url'] = is_string($value) ? trim($value) : $value;
    }

    public function setImagePathAttribute(mixed $value): void
    {
        $this->attributes['image_path'] = is_string($value) ? trim($value) : $value;
    }

    public function setTitleAttribute(mixed $value): void
    {
        $title = is_string($value) ? trim($value) : $value;

        $this->attributes['title'] = $title;
        $this->attributes['siblings_collection_name'] = self::resolvedSiblingOptionName(
            $title,
            $this->attributes['siblings_collection_name'] ?? null
        );
    }

    public function setSiblingsCollectionNameAttribute(mixed $value): void
    {
        $this->attributes['siblings_collection_name'] = self::resolvedSiblingOptionName(
            $this->attributes['title'] ?? null,
            $value
        );
    }

    public function getSeoDeindexAttribute(): bool
    {
        $payload = is_array($this->payload) ? $this->payload : [];
        $value = $payload[HeaderStore::SEO_DEINDEX] ?? null;

        return filter_var($value, FILTER_VALIDATE_BOOLEAN);
    }

    public function setSeoDeindexAttribute(mixed $value): void
    {
        $payload = is_array($this->payload) ? $this->payload : [];

        if ($value === null || $value === '') {
            unset($payload[HeaderStore::SEO_DEINDEX]);
        } else {
            $payload[HeaderStore::SEO_DEINDEX] = filter_var($value, FILTER_VALIDATE_BOOLEAN) ? 'true' : 'false';
        }

        $this->attributes['payload'] = $payload;
    }

    private static function resolvedSiblingOptionName(mixed $title, mixed $fallback = null): ?string
    {
        $normalizedTitle = is_string($title) ? trim($title) : trim((string) ($title ?? ''));
        if ($normalizedTitle !== '') {
            return $normalizedTitle;
        }

        $normalizedFallback = is_string($fallback) ? trim($fallback) : trim((string) ($fallback ?? ''));

        return $normalizedFallback !== '' ? $normalizedFallback : null;
    }

    public function approvals(): HasMany
    {
        return $this->hasMany(NewProductDraftApproval::class);
    }

    public function product(): HasOne
    {
        return $this->hasOne(Product::class, 'handle', 'handle');
    }

    public function styleProfiles(): HasMany
    {
        return $this->hasMany(StyleProfile::class, 'handle', 'handle');
    }

    public function deletionRequests(): MorphMany
    {
        return $this->morphMany(DeletionRequest::class, 'deletable');
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

    public function isBlockedFromShopifyMissing(): bool
    {
        return (bool) ($this->shopify_missing_sync_blocked ?? false);
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
                && !in_array($field, ['image_url', 'image_path'], true)
                && is_string($warning['field'] ?? null)
                && is_string($warning['label'] ?? null)
                && array_key_exists('draft_value', $warning)
                && array_key_exists('shopify_value', $warning);
        }));
    }
}
