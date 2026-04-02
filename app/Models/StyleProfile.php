<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use App\Services\HeaderStore;
use App\Models\ShopifyRow;

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
        'seo_sync_status','seo_synced_at',
        'applied_at','applied_by',
    ];

    protected $casts = [
        'applied_at' => 'datetime',
        'seo_synced_at' => 'datetime',
    ];

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    protected static function booted(): void
    {
        static::saving(function (self $profile): void {
            if (!$profile->seo_sync_status) {
                $profile->seo_sync_status = 'draft';
            }

            if ($profile->isDirty(['draft_seo_title', 'draft_seo_description']) && $profile->seo_sync_status === 'synced') {
                $profile->seo_sync_status = 'ready';
                $profile->seo_synced_at = null;
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
