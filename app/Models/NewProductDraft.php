<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\Storage;

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

    public function imageUrl(): ?string
    {
        if ($this->image_url) {
            return $this->image_url;
        }

        if ($this->image_path) {
            return Storage::disk('public')->url($this->image_path);
        }

        return null;
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
