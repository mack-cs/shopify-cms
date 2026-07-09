<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use App\Services\CategoryTypeMap;
use App\Services\HeaderStore;
use App\Services\TagNormalizer;
use App\Models\StyleProfile;
use App\Models\Product;
use App\Models\User;

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
    private const SALE_TAG = 'sale';
    private const EXCLUDE_FROM_SALE_TAG = 'exclude-from-the-sale';
    private const DEFAULT_NEW_PRODUCT_TAGS = [
        'all-products-collection',
        'all-products',
    ];
    private const PRODUCT_TYPE_TAGS = [
        'anklet',
        'bracelet',
        'bundle',
        'bundles',
        'charm',
        'earring',
        'necklace',
        'ring',
        'stack',
        'stacks',
    ];

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
        'is_on_sale',
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
        'bundle_product_ids',
        'bundle_image_urls',
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
        'editing_user_id',
        'editing_started_at',
        'editing_expires_at',
    ];

    protected $casts = [
        'payload' => 'array',
        'shopify_sync_warnings' => 'array',
        'shopify_missing_detected_at' => 'datetime',
        'shopify_missing_sync_blocked' => 'boolean',
        'is_on_sale' => 'boolean',
        'bundle_product_ids' => 'array',
        'bundle_image_urls' => 'array',
        'variant_price' => 'decimal:2',
        'variant_compare_at_price' => 'decimal:2',
        'variant_inventory_qty' => 'integer',
        'material_cost' => 'decimal:2',
        'editing_started_at' => 'datetime',
        'editing_expires_at' => 'datetime',
    ];

    protected static function booted(): void
    {
        static::saving(function (NewProductDraft $draft): void {
            $current = is_string($draft->google_product_category)
                ? trim($draft->google_product_category)
                : '';

            if ($current === '') {
                $resolved = CategoryTypeMap::resolve(
                    is_string($draft->product_category) ? $draft->product_category : null,
                    is_string($draft->type) ? $draft->type : null,
                    null
                );

                $google = trim((string) ($resolved['google_product_category'] ?? ''));
                if ($google !== '') {
                    $draft->google_product_category = $google;
                }
            }

            $draft->applyDefaultSaleTags();
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

    private function applyDefaultSaleTags(): void
    {
        $tags = self::normalizeBundleCollectionTags(
            TagNormalizer::parseTokens((string) ($this->attributes['tags'] ?? ''))
        );
        $isOnSale = (bool) ($this->attributes['is_on_sale'] ?? false)
            || in_array(self::SALE_TAG, $tags, true);

        $tags = array_values(array_filter(
            $tags,
            fn (string $tag): bool => !in_array($tag, [self::SALE_TAG, self::EXCLUDE_FROM_SALE_TAG], true)
        ));

        foreach (self::DEFAULT_NEW_PRODUCT_TAGS as $defaultTag) {
            $tags[] = $defaultTag;
        }

        $typeTag = self::hasBundleOrStackTag($tags)
            ? 'bundles'
            : self::defaultTagForType($this->attributes['type'] ?? null);
        if ($typeTag !== null) {
            $tags = array_values(array_filter(
                $tags,
                fn (string $tag): bool => !in_array($tag, self::PRODUCT_TYPE_TAGS, true) || $tag === $typeTag
            ));
            $tags[] = $typeTag;
        }

        $tags[] = $isOnSale ? self::SALE_TAG : self::EXCLUDE_FROM_SALE_TAG;

        $this->attributes['is_on_sale'] = $isOnSale;
        $this->attributes['tags'] = TagNormalizer::normalizeFromArray(
            self::normalizeBundleCollectionTags($tags)
        );
    }

    private static function defaultTagForType(mixed $type): ?string
    {
        $token = TagNormalizer::normalizeToken((string) ($type ?? ''));
        if ($token === null) {
            return null;
        }

        return match ($token) {
            'anklet', 'anklets' => 'anklet',
            'bracelet', 'bracelets' => 'bracelet',
            'bundle', 'bundles', 'stack', 'stacks' => 'bundles',
            'charm', 'charms' => 'charm',
            'earring', 'earrings' => 'earring',
            'necklace', 'necklaces' => 'necklace',
            'ring', 'rings' => 'ring',
            default => str_ends_with($token, 's') && strlen($token) > 3
                ? substr($token, 0, -1)
                : $token,
        };
    }

    /**
     * @param array<int, string> $tags
     * @return array<int, string>
     */
    private static function normalizeBundleCollectionTags(array $tags): array
    {
        $tags = TagNormalizer::parseTokens(TagNormalizer::normalizeFromArray($tags));
        if (!self::hasBundleOrStackTag($tags)) {
            return $tags;
        }

        $remove = ['bundle', 'stack', 'stacks'];
        foreach ($tags as $tag) {
            foreach (['-bundles', '-bundle', '-stacks', '-stack'] as $suffix) {
                if (!str_ends_with($tag, $suffix)) {
                    continue;
                }

                $base = substr($tag, 0, -strlen($suffix));
                if ($base !== '') {
                    $remove[] = $base;
                }
            }
        }

        $tags = array_values(array_filter(
            $tags,
            fn (string $tag): bool => !in_array($tag, array_unique($remove), true)
        ));
        $tags[] = 'bundles';

        return TagNormalizer::parseTokens(TagNormalizer::normalizeFromArray($tags));
    }

    /**
     * @param array<int, string> $tags
     */
    private static function hasBundleOrStackTag(array $tags): bool
    {
        foreach (TagNormalizer::parseTokens(TagNormalizer::normalizeFromArray($tags)) as $tag) {
            if (in_array($tag, ['bundle', 'bundles', 'stack', 'stacks'], true)) {
                return true;
            }

            if (
                str_ends_with($tag, '-bundle')
                || str_ends_with($tag, '-bundles')
                || str_ends_with($tag, '-stack')
                || str_ends_with($tag, '-stacks')
            ) {
                return true;
            }
        }

        return false;
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

    public function editingUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'editing_user_id');
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

    public function isPendingApproval(): bool
    {
        if (filled(trim((string) ($this->handle ?? '')))) {
            $product = null;

            $shopifyId = trim((string) ($this->shopify_id ?? ''));
            if ($shopifyId !== '') {
                $product = Product::query()
                    ->where('shopify_id', $shopifyId)
                    ->first();
            }

            if (!$product instanceof Product) {
                $handle = trim((string) ($this->handle ?? ''));
                if ($handle === '') {
                    return false;
                }

                $product = Product::query()
                    ->where('handle', $handle)
                    ->first();
            }

            if (!$product instanceof Product) {
                return false;
            }

            return $product->partialApprovalRequests()
                ->where('approval_version', $product->approval_version)
                ->where('status', ProductPartialApprovalRequest::STATUS_PENDING)
                ->exists();
        }

        $count = $this->approvalsForCurrentVersionCount();

        return $count > 0 && $count < 2;
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

    public function isActivelyEditedByAnotherUser(?int $userId, int $ttlMinutes = 15): bool
    {
        $this->clearExpiredEditLock($ttlMinutes);

        $editingUserId = (int) ($this->editing_user_id ?? 0);
        if ($editingUserId <= 0) {
            return false;
        }

        if ($userId !== null && $editingUserId === $userId) {
            return false;
        }

        return $this->editing_expires_at !== null && $this->editing_expires_at->isFuture();
    }

    public function acquireEditLock(int $userId, int $ttlMinutes = 15): bool
    {
        return (bool) static::query()
            ->whereKey($this->getKey())
            ->where(function ($query) use ($userId): void {
                $query->whereNull('editing_user_id')
                    ->orWhere('editing_user_id', $userId)
                    ->orWhereNull('editing_expires_at')
                    ->orWhere('editing_expires_at', '<=', now());
            })
            ->update([
                'editing_user_id' => $userId,
                'editing_started_at' => $this->editing_user_id === $userId && $this->editing_started_at
                    ? $this->editing_started_at
                    : now(),
                'editing_expires_at' => now()->addMinutes($ttlMinutes),
                'updated_at' => $this->updated_at,
            ]) > 0;
    }

    public function refreshEditLock(int $userId, int $ttlMinutes = 15): bool
    {
        return (bool) static::query()
            ->whereKey($this->getKey())
            ->where('editing_user_id', $userId)
            ->update([
                'editing_expires_at' => now()->addMinutes($ttlMinutes),
                'updated_at' => $this->updated_at,
            ]) > 0;
    }

    public function releaseEditLock(?int $userId = null): bool
    {
        $query = static::query()->whereKey($this->getKey());
        if ($userId !== null) {
            $query->where('editing_user_id', $userId);
        }

        return (bool) $query->update([
            'editing_user_id' => null,
            'editing_started_at' => null,
            'editing_expires_at' => null,
            'updated_at' => $this->updated_at,
        ]) > 0;
    }

    public function clearExpiredEditLock(int $ttlMinutes = 15): void
    {
        $expiresAt = $this->editing_expires_at;
        if ($this->editing_user_id === null) {
            return;
        }

        if ($expiresAt !== null && $expiresAt->isFuture()) {
            return;
        }

        $this->releaseEditLock();
        $this->refresh();
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
                && !in_array($field, ['image_url', 'image_path','batch'], true)
                && is_string($warning['field'] ?? null)
                && is_string($warning['label'] ?? null)
                && array_key_exists('draft_value', $warning)
                && array_key_exists('shopify_value', $warning)
                && !static::shopifySyncWarningValuesMatch(
                    $field,
                    $warning['draft_value'] ?? null,
                    $warning['shopify_value'] ?? null,
                );
        }));
    }

    private static function shopifySyncWarningValuesMatch(string $field, mixed $draftValue, mixed $shopifyValue): bool
    {
        return static::normalizeShopifySyncWarningValue($field, $draftValue)
            === static::normalizeShopifySyncWarningValue($field, $shopifyValue);
    }

    private static function normalizeShopifySyncWarningValue(string $field, mixed $value): string
    {
        $string = trim((string) $value);

        return match ($field) {
            'published',
            'seo_deindex',
            'is_on_sale' => static::normalizeBooleanShopifySyncWarningValue($string),
            'status' => strtolower($string),
            default => $string,
        };
    }

    private static function normalizeBooleanShopifySyncWarningValue(string $value): string
    {
        $normalized = strtolower(trim($value));

        return match ($normalized) {
            '1', 'true', 'yes', 'y', 'on' => 'true',
            '0', 'false', 'no', 'n', 'off' => 'false',
            default => $normalized,
        };
    }
}
