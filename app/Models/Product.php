<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Illuminate\Support\Facades\DB;
use App\Services\CategoryTypeMap;

class Product extends Model
{
    protected $fillable = [
        'import_id','shopify_id','handle','approved_handle','title','body_html','vendor','tags',
        'type','published',
        'product_category','google_product_category','status',
        'seo_title','seo_description','color_string','uvp_short_paragraph','approval_version',
        'first_image_auto_rename_completed_at','first_image_auto_rename_approval_version',
        'first_handle_auto_lock_completed_at','first_handle_auto_lock_approval_version',
        'batch','sync_batch_id','last_synced_at','is_bundle','you_save',
        'has_errors','error_fields',
    ];

    protected $casts = [
        'is_bundle' => 'boolean',
        'you_save' => 'decimal:2',
        'has_errors' => 'boolean',
        'error_fields' => 'array',
        'last_synced_at' => 'datetime',
        'seo_updated_at' => 'datetime',
        'first_image_auto_rename_completed_at' => 'datetime',
        'first_handle_auto_lock_completed_at' => 'datetime',
    ];

    protected static function booted(): void
    {
        static::saving(function (Product $product): void {
            $current = is_string($product->google_product_category)
                ? trim($product->google_product_category)
                : '';
            if ($current !== '') {
                return;
            }

            $resolved = CategoryTypeMap::resolve(
                is_string($product->product_category) ? $product->product_category : null,
                is_string($product->type) ? $product->type : null,
                null
            );

            $google = trim((string) ($resolved['google_product_category'] ?? ''));
            if ($google !== '') {
                $product->google_product_category = $google;
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

    public function import(): BelongsTo
    {
        return $this->belongsTo(Import::class);
    }

    public function seoUpdatedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'seo_updated_by');
    }

    public function variants(): HasMany
    {
        return $this->hasMany(Variant::class)->active();
    }

    public function allVariants(): HasMany
    {
        return $this->hasMany(Variant::class);
    }

    public function images(): HasMany
    {
        return $this->hasMany(Image::class)->active();
    }

    public function scopeActiveStatus(Builder $query): Builder
    {
        return $query->whereRaw('LOWER(TRIM(COALESCE(status, ""))) = ?', ['active']);
    }

    public function scopeMissingImageAltText(Builder $query): Builder
    {
        return $query->whereHas('images', fn (Builder $imageQuery): Builder => self::applyMissingImageAltTextImageFilter($imageQuery));
    }

    public function scopeActiveMissingImageAltText(Builder $query): Builder
    {
        return $query->activeStatus()->missingImageAltText();
    }

    public static function applyMissingImageAltTextImageFilter(Builder $query): Builder
    {
        return $query->where(function (Builder $altQuery): void {
            $altQuery->whereNull('alt_text')
                ->orWhereRaw("TRIM(COALESCE(alt_text, '')) = ''");
        });
    }

    public function allImages(): HasMany
    {
        return $this->hasMany(Image::class);
    }

    public function approvals(): HasMany
    {
        return $this->hasMany(Approval::class);
    }

    public function partialApprovalRequests(): HasMany
    {
        return $this->hasMany(ProductPartialApprovalRequest::class);
    }

    public function shopifyAudits(): HasMany
    {
        return $this->hasMany(ShopifyAudit::class);
    }

    public function complementaryProductsAudit(): HasOne
    {
        return $this->hasOne(ShopifyAudit::class)
            ->where('audit_type', ShopifyAudit::TYPE_COMPLEMENTARY_PRODUCTS);
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

    public function latestApprovalAt(): ?\Illuminate\Support\Carbon
    {
        $approval = $this->approvalsForCurrentVersion()
            ->latest('created_at')
            ->first();

        return $approval?->created_at;
    }

    public function latestPartialApprovalAt(): ?\Illuminate\Support\Carbon
    {
        $request = $this->partialApprovalRequests()
            ->where('approval_version', $this->approval_version)
            ->where('status', ProductPartialApprovalRequest::STATUS_APPROVED)
            ->latest('approved_at')
            ->first();

        return $request?->approved_at;
    }

    public function changeLogs(): HasMany
    {
        return $this->hasMany(ChangeLog::class);
    }

    public function styleProfiles(): HasMany
    {
        return $this->hasMany(StyleProfile::class);
    }

    public function deletionRequests(): MorphMany
    {
        return $this->morphMany(DeletionRequest::class, 'deletable');
    }

    public function urlRedirects(): HasMany
    {
        return $this->hasMany(ProductUrlRedirect::class);
    }

    public function desiredHandle(): ?string
    {
        $approved = trim((string) ($this->approved_handle ?? ''));
        if ($approved !== '') {
            return $approved;
        }

        $handle = trim((string) ($this->handle ?? ''));
        return $handle === '' ? null : $handle;
    }

    public function hasLockedApprovedHandle(): bool
    {
        return $this->first_handle_auto_lock_completed_at !== null;
    }

    public function shopifySyncState(): string
    {
        if (!$this->hasBeenSynced()) {
            return 'No Sync';
        }

        if ($this->hasChangesSinceLastSync()) {
            return 'Updated After Sync';
        }

        return 'Synced';
    }

    public function hasBeenSynced(): bool
    {
        return $this->last_synced_at !== null;
    }

    public function hasChangesSinceLastSync(): bool
    {
        return $this->last_synced_at !== null
            && $this->updated_at !== null
            && $this->updated_at->gt($this->last_synced_at);
    }

    public function isSyncedAndCurrent(): bool
    {
        return $this->last_synced_at !== null && !$this->hasChangesSinceLastSync();
    }

    public function hasDuplicateImagePositions(): bool
    {
        $duplicatePositions = Image::query()
            ->where('product_id', $this->getKey())
            ->whereNotNull('position')
            ->whereNotIn('sync_state', [
                Image::SYNC_STATE_LOCAL_DELETED,
                Image::SYNC_STATE_REMOTE_DELETED,
            ])
            ->where(function ($query): void {
                $query->whereNull('is_duplicate_hidden')
                    ->orWhere('is_duplicate_hidden', false);
            })
            ->select('position')
            ->groupBy('position')
            ->havingRaw('COUNT(*) > 1');

        return DB::query()
            ->fromSub($duplicatePositions, 'duplicate_image_positions')
            ->exists();
    }



}
