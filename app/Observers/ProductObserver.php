<?php

namespace App\Observers;

use App\Models\ChangeLog;
use App\Models\Product;
use App\Models\ShopifyRow;
use App\Services\Normalizer;
use App\Services\TagNormalizer;
use App\Models\Tag;
use App\Models\NewProductDraft;
use App\Services\HeaderStore;
use Illuminate\Support\Facades\Auth;

class ProductObserver
{
    public function updating(Product $product): void
    {
        $dirty = $product->getDirty();
        if (empty($dirty)) return;

        // 1) Decide which fields should NOT reset approvals
        $ignoreForApprovalReset = [
            'updated_at',
            'created_at',
            // add internal-only fields you don't want to trigger re-approval
            // e.g. 'batch', 'notes'
            'batch',
            'is_bundle',
            'you_save',
            'seo_title',
            'seo_description',
        ];

        // 2) If any meaningful field changed, bump approval_version
        $dirtyKeys = array_keys($dirty);
        $meaningful = array_diff($dirtyKeys, $ignoreForApprovalReset);

        // IMPORTANT: don't treat approval_version itself as meaningful
        $meaningful = array_diff($meaningful, ['approval_version']);

        if (!empty($meaningful)) {
            $product->approval_version = ($product->approval_version ?? 1) + 1;

            // Also ensure we don't log approval_version as if user changed it
            $dirty['approval_version'] = $product->approval_version;
        }

        // 3) Log changes (skip approval_version + timestamps so logs stay clean)
        $ignoreForLogging = [
            'updated_at',
            'created_at',
            'approval_version', // recommended: it's system-driven
        ];

        $userId = Auth::id();

        foreach ($dirty as $field => $newValue) {
            if (in_array($field, $ignoreForLogging, true)) {
                continue;
            }

            ChangeLog::create([
                'import_id'   => $product->import_id,
                'product_id'  => $product->id,
                'changed_by'  => $userId,
                'model_type'  => Product::class,
                'model_id'    => $product->id,
                'field'       => $field,
                'old_value'   => (string) $product->getOriginal($field),
                'new_value'   => is_scalar($newValue) ? (string)$newValue : json_encode($newValue),
            ]);
        }
    }

    public function updated(Product $product): void
    {
        if ($product->wasChanged('tags')) {
            $normalized = TagNormalizer::normalizeString($product->tags);
            if ($normalized !== $product->tags) {
                Product::withoutEvents(function () use ($product, $normalized): void {
                    $product->forceFill(['tags' => $normalized])->save();
                });
            }
        }

        if ($product->wasChanged('tags')) {
            $tokens = TagNormalizer::parseTokens($product->tags);
            $isBundle = in_array('bundle', $tokens, true) || in_array('bundles', $tokens, true);
            if ($product->is_bundle !== $isBundle) {
                Product::withoutEvents(function () use ($product, $isBundle): void {
                    $product->forceFill(['is_bundle' => $isBundle])->save();
                });
            }
        }

        app(Normalizer::class)->recalculateErrorsForProduct($product);
        $this->syncTagsForProduct($product);

        $this->syncDraftForProduct($product);
    }

    private function syncDraftForProduct(Product $product): void
    {
        if (!$product->handle) {
            return;
        }

        $variant = $product->variants()->orderBy('id')->first();
        $sku = trim((string) ($variant?->sku ?? ''));
        $imageUrl = $product->images()->orderBy('position')->value('src');

        $row = ShopifyRow::where('import_id', $product->import_id)
            ->where('handle', $product->handle)
            ->where('row_type', 'product_primary')
            ->first();
        $costPerItem = $row?->get(HeaderStore::COST_PER_ITEM, null);

        $payload = [
            'handle' => $product->handle,
            'title' => $product->title,
            'body_html' => $product->body_html,
            'vendor' => $product->vendor,
            'tags' => $product->tags,
            'type' => $product->type,
            'published' => $product->published,
            'product_category' => $product->product_category,
            'google_product_category' => $product->google_product_category,
            'status' => $product->status,
            'color_string' => $product->color_string,
            'batch' => $product->batch,
        ];

        if ($sku !== '') {
            $payload['sku'] = $sku;
        }
        if ($imageUrl) {
            $payload['image_url'] = $imageUrl;
        }
        if ($variant?->price !== null) {
            $payload['variant_price'] = $variant->price;
        }
        if ($variant?->compare_at_price !== null) {
            $payload['variant_compare_at_price'] = $variant->compare_at_price;
        }
        if ($variant?->inventory_qty !== null) {
            $payload['variant_inventory_qty'] = $variant->inventory_qty;
        }

        if ($row) {
            $payload['material_cost'] = $row->get(HeaderStore::MATERIAL_COST, null);
            $payload['jewelry_material'] = $row->get(HeaderStore::JEWELRY_MATERIAL, null);
            $payload['product_materials'] = $row->get(HeaderStore::PRODUCT_MATERIALS, null);
            $payload['materials_and_dimensions'] = $row->get(HeaderStore::MATERIALS_AND_DIMENSIONS, null);
            $payload['product_design'] = $row->get(HeaderStore::BRACELET_DESIGN, null);
            $payload['metal'] = $row->get(HeaderStore::PRODUCT_METALS, null);
            $payload['colour_style'] = $row->get(HeaderStore::PATTERN_CATEGORY, null);
            $payload['size'] = $row->get(HeaderStore::SIZE, null);
            $payload['siblings'] = $row->get(HeaderStore::SIBLINGS, null);
            $payload['siblings_collection_name'] = $row->get(HeaderStore::SIBLINGS_COLLECTION_NAME, null);
            $payload['uvp_short_paragraph'] = $row->get(HeaderStore::UVP_SHORT_PARAGRAPH, null);
            $payload['complementary_products'] = $row->get(HeaderStore::COMPLEMENTARY_PRODUCTS, null);
        }

        NewProductDraft::withoutEvents(function () use ($product, $payload): void {
            NewProductDraft::updateOrCreate(
                ['handle' => $product->handle],
                $payload
            );
        });
    }

    private function syncTagsForProduct(Product $product): void
    {
        $tokens = TagNormalizer::parseTokens($product->tags);
        if (empty($tokens)) {
            return;
        }

        foreach ($tokens as $token) {
            Tag::firstOrCreate(['name' => $token], ['active' => true]);
        }
    }
}
