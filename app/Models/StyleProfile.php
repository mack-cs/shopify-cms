<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use App\Services\HeaderStore;
use App\Models\ShopifyRow;
use Illuminate\Support\Facades\Auth;

class StyleProfile extends Model
{
    public const SEO_TITLE_RECOMMENDED_MIN = 50;
    public const SEO_TITLE_RECOMMENDED_MAX = 60;
    public const SEO_DESCRIPTION_RECOMMENDED_MIN = 150;
    public const SEO_DESCRIPTION_RECOMMENDED_MAX = 160;

    protected $fillable = [
        'product_id','handle','sku','image_url',
        'style_type','materials','components','colour_prompt',
        'draft_title','draft_description','draft_seo_title','draft_seo_description','draft_image_alt_text',
        'seo_updated_at','seo_updated_by',
        'seo_approved_at','seo_approved_by','seo_approval_source','seo_approval_request_id',
        'seo_sync_status','seo_synced_at',
        'seo_synced_by','seo_sync_batch_id',
        'applied_at','applied_by',
    ];

    protected $casts = [
        'applied_at' => 'datetime',
        'seo_updated_at' => 'datetime',
        'seo_approved_at' => 'datetime',
        'seo_synced_at' => 'datetime',
    ];

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    public function seoUpdatedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'seo_updated_by');
    }

    public function seoApprovedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'seo_approved_by');
    }

    public function seoApprovalRequest(): BelongsTo
    {
        return $this->belongsTo(ProductPartialApprovalRequest::class, 'seo_approval_request_id');
    }

    public function seoSyncedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'seo_synced_by');
    }

    public function appliedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'applied_by');
    }

    protected static function booted(): void
    {
        static::saving(function (self $profile): void {
            if (!$profile->seo_sync_status) {
                $profile->seo_sync_status = 'draft';
            }

            if ($profile->isDirty(['draft_seo_title', 'draft_seo_description'])) {
                $profile->seo_updated_at = now();
                $profile->seo_updated_by = Auth::id();
                $profile->seo_approved_at = null;
                $profile->seo_approved_by = null;
                $profile->seo_approval_source = null;
                $profile->seo_approval_request_id = null;
                $profile->seo_synced_at = null;
                $profile->seo_synced_by = null;
                $profile->seo_sync_batch_id = null;

                if (in_array($profile->seo_sync_status, ['approved', 'synced'], true)) {
                    $profile->seo_sync_status = 'ready';
                }
            }

            if (!$profile->product_id) {
                return;
            }

            $handle = Product::where('id', $profile->product_id)->value('handle');
            if ($handle) {
                $profile->handle = $handle;
            }

            if (!$profile->sku && $profile->handle) {
                $profile->sku = $profile->handle;
            }
        });

        static::saved(function (self $profile): void {
            if (!$profile->product_id) {
                return;
            }

            if (!$profile->wasChanged(['draft_seo_title', 'draft_seo_description'])) {
                return;
            }

            $product = $profile->product;
            if (!$product) {
                return;
            }

            $payload = [];
            $seoTitle = self::nullIfEmpty($profile->draft_seo_title);
            $seoDescription = self::nullIfEmpty($profile->draft_seo_description);

            if ($product->seo_title !== $seoTitle) {
                $payload['seo_title'] = $seoTitle;
            }
            if ($product->seo_description !== $seoDescription) {
                $payload['seo_description'] = $seoDescription;
            }

            if ($payload) {
                $product->update($payload);
            }

            $row = ShopifyRow::where('import_id', $product->import_id)
                ->where('handle', $product->handle)
                ->where('row_type', 'product_primary')
                ->first();

            if ($row) {
                $row->set(HeaderStore::SEO_TITLE, $seoTitle ?? '');
                $row->set(HeaderStore::SEO_DESCRIPTION, $seoDescription ?? '');
                $row->save();
            }
        });
    }

    private static function nullIfEmpty(?string $value): ?string
    {
        if ($value === null) {
            return null;
        }

        $trimmed = trim($value);
        return $trimmed === '' ? null : $trimmed;
    }

    public static function trimmedLength(mixed $value): int
    {
        return mb_strlen(trim((string) ($value ?? '')));
    }

    public static function seoTitleLengthHint(mixed $value): string
    {
        return self::recommendedLengthHint(
            $value,
            self::SEO_TITLE_RECOMMENDED_MIN,
            self::SEO_TITLE_RECOMMENDED_MAX
        );
    }

    public static function seoDescriptionLengthHint(mixed $value): string
    {
        return self::recommendedLengthHint(
            $value,
            self::SEO_DESCRIPTION_RECOMMENDED_MIN,
            self::SEO_DESCRIPTION_RECOMMENDED_MAX
        );
    }

    private static function recommendedLengthHint(mixed $value, int $min, int $max): string
    {
        $length = self::trimmedLength($value);

        if ($length === 0) {
            return "Recommended {$min}-{$max} characters.";
        }

        if ($length < $min) {
            return "Too short: {$length} characters. Recommended {$min}-{$max}.";
        }

        if ($length > $max) {
            return "Too long: {$length} characters. Recommended {$min}-{$max}.";
        }

        return "Length looks good: {$length} characters. Recommended {$min}-{$max}.";
    }
}
