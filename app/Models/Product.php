<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use App\Services\CategoryTypeMap;

class Product extends Model
{
    protected $fillable = [
        'import_id','shopify_id','handle','title','body_html','vendor','tags',
        'type','published',
        'product_category','google_product_category','status',
        'seo_title','seo_description','color_string','uvp_short_paragraph','approval_version',
        'batch','is_bundle','you_save',
        'has_errors','error_fields',
    ];

    protected $casts = [
        'is_bundle' => 'boolean',
        'you_save' => 'decimal:2',
        'has_errors' => 'boolean',
        'error_fields' => 'array',
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

    public function variants(): HasMany
    {
        return $this->hasMany(Variant::class);
    }

    public function images(): HasMany
    {
        return $this->hasMany(Image::class);
    }

    public function approvals(): HasMany
    {
        return $this->hasMany(Approval::class);
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

    public function changeLogs(): HasMany
    {
        return $this->hasMany(ChangeLog::class);
    }

    public function styleProfiles(): HasMany
    {
        return $this->hasMany(StyleProfile::class);
    }



}
