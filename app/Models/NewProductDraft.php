<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\Storage;
use App\Services\CategoryTypeMap;

class NewProductDraft extends Model
{
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
        'uvp_short_paragraph',
        'complementary_products',
        'variant_inventory_policy',
        'variant_fulfillment_service',
        'payload',
        'approval_version',
        'created_by',
    ];

    protected $casts = [
        'payload' => 'array',
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

    public function approvals(): HasMany
    {
        return $this->hasMany(NewProductDraftApproval::class);
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
