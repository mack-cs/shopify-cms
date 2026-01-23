<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class StyleProfile extends Model
{
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
}
