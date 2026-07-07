<?php

namespace App\Observers;

use App\Models\ChangeLog;
use App\Models\Product;
use App\Models\ShopifyRow;
use App\Services\Normalizer;
use App\Services\ProductSeoTracker;
use App\Services\TagNormalizer;
use App\Models\Tag;
use App\Models\NewProductDraft;
use App\Services\HeaderStore;
use App\Services\InventoryOperationContext;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

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
            'approved_handle',
            'first_handle_auto_lock_completed_at',
            'first_handle_auto_lock_approval_version',
            'first_image_auto_rename_completed_at',
            'first_image_auto_rename_approval_version',
            'sync_batch_id',
            'last_synced_at',
            'image_import_batch_id',
            'image_imported_at',
            'image_import_status',
            'seo_updated_at',
            'seo_updated_by',
        ];

        if (InventoryOperationContext::active()) {
            $ignoreForApprovalReset[] = 'status';
        }

        // 2) If any meaningful field changed, bump approval_version
        $dirtyKeys = array_keys($dirty);
        $seoTrackedFields = [
            'seo_title',
            'seo_description',
        ];

        if (!empty(array_intersect($dirtyKeys, $seoTrackedFields))) {
            $tracker = app(ProductSeoTracker::class);
            $tracker->stampAttributes($product, Auth::id());
            $dirty['seo_updated_at'] = $product->seo_updated_at;
            $dirty['seo_updated_by'] = $product->seo_updated_by;
            $dirtyKeys = array_keys($dirty);
        }

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
            'approved_handle',
            'first_handle_auto_lock_completed_at',
            'first_handle_auto_lock_approval_version',
            'first_image_auto_rename_completed_at',
            'first_image_auto_rename_approval_version',
            'sync_batch_id',
            'last_synced_at',
            'image_import_batch_id',
            'image_imported_at',
            'image_import_status',
            'seo_updated_at',
            'seo_updated_by',
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
            $isBundle = TagNormalizer::containsBundleOrStackTag($product->tags);
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
            'shopify_id' => $product->shopify_id,
            'title' => $product->title,
            'siblings_collection_name' => $product->title,
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
            'origin' => NewProductDraft::ORIGIN_PRODUCT_MIRROR,
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
        $payload['variant_inventory_qty'] = $variant?->inventory_tracked === false
            ? null
            : ($variant?->inventory_qty !== null ? (int) $variant->inventory_qty : null);

        if ($row) {
            $payload['material_cost'] = $row->get(HeaderStore::MATERIAL_COST, null);
            $payload['jewelry_material'] = $row->get(HeaderStore::JEWELRY_MATERIAL, null);
            $payload['product_materials'] = $row->get(HeaderStore::PRODUCT_MATERIALS, null);
            $payload['materials_and_dimensions'] = $row->get(HeaderStore::MATERIALS_AND_DIMENSIONS, null);
            $payload['product_design'] = $this->designValueFromRow($product, $row);
            $payload['metal'] = $row->get(HeaderStore::PRODUCT_METALS, null);
            $payload['colour_style'] = $row->get(HeaderStore::PATTERN_CATEGORY, null);
            $payload['size'] = $row->get(HeaderStore::SIZE, null);
            $payload['siblings'] = $row->get(HeaderStore::SIBLINGS, null);
            $payload['sibling_collection'] = $row->get(HeaderStore::SIBLING_COLLECTION, null);
            $payload['uvp_short_paragraph'] = $row->get(HeaderStore::UVP_SHORT_PARAGRAPH, null);
            $payload['complementary_products'] = $row->get(HeaderStore::COMPLEMENTARY_PRODUCTS, null);
            $payload['payload'] = $this->extraDraftPayloadFromRow($product, $row);
        }

        NewProductDraft::withoutEvents(function () use ($product, $payload): void {
            $draft = $this->findDraftForProduct($product);

            if (!$draft) {
                NewProductDraft::create($payload);
                return;
            }

            $updates = $this->draftUpdatesForPayload($draft, $payload);

            if (!empty($updates)) {
                $draft->fill($updates)->save();
            }
        });
    }

    private function isEmptyDraftValue(mixed $value): bool
    {
        if ($value === null) {
            return true;
        }

        if (is_string($value)) {
            return trim($value) === '';
        }

        if (is_array($value)) {
            return empty($value);
        }

        return false;
    }

    private function findDraftForProduct(Product $product): ?NewProductDraft
    {
        $handle = trim((string) ($product->handle ?? ''));
        $shopifyId = trim((string) ($product->shopify_id ?? ''));
        $handleDraft = null;

        if ($handle !== '') {
            $handleDraft = NewProductDraft::query()
                ->where('handle', $handle)
                ->first();
        }

        $shopifyDraft = null;
        if ($shopifyId !== '') {
            $shopifyDraft = NewProductDraft::query()
                ->where('shopify_id', $shopifyId)
                ->first();
        }

        if ($handleDraft && $shopifyDraft && $handleDraft->isNot($shopifyDraft)) {
            $preferred = $this->preferredDraftForProduct($handleDraft, $shopifyDraft);

            return $this->mergeConflictingDrafts(
                $preferred,
                $preferred->is($handleDraft) ? $shopifyDraft : $handleDraft
            );
        }

        return $handleDraft ?? $shopifyDraft;
    }

    private function designValueFromRow(Product $product, ?ShopifyRow $row): ?string
    {
        if (!$row) {
            return null;
        }

        $resolvedHeader = HeaderStore::designHeaderForTypeAndTags($product->type, $product->tags);
        $headers = $resolvedHeader !== null
            ? array_values(array_unique(array_merge([$resolvedHeader], HeaderStore::designHeaders())))
            : HeaderStore::designHeaders();

        foreach ($headers as $header) {
            $value = $row->get($header, null);
            if (is_string($value) && trim($value) !== '') {
                return trim($value);
            }
        }

        return null;
    }

    /**
     * @return array<string, string>|null
     */
    private function extraDraftPayloadFromRow(Product $product, ?ShopifyRow $row): ?array
    {
        if (!$row) {
            return null;
        }

        $payload = [];
        foreach (HeaderStore::extraProductHeadersForDraftWorkflow($product->import?->headers ?? []) as $header) {
            $value = $row->get($header, null);
            if (!is_string($value)) {
                continue;
            }

            $trimmed = trim($value);
            if ($trimmed === '') {
                continue;
            }

            $payload[$header] = $trimmed;
        }

        return $payload === [] ? null : $payload;
    }

    private function preferredDraftForProduct(NewProductDraft $handleDraft, NewProductDraft $shopifyDraft): NewProductDraft
    {
        if ($handleDraft->origin === NewProductDraft::ORIGIN_DRAFT_TOOL) {
            return $handleDraft;
        }

        if ($shopifyDraft->origin === NewProductDraft::ORIGIN_DRAFT_TOOL) {
            return $shopifyDraft;
        }

        if ($handleDraft->origin === NewProductDraft::ORIGIN_PRODUCT_MIRROR) {
            return $shopifyDraft;
        }

        return $handleDraft;
    }

    private function mergeConflictingDrafts(NewProductDraft $preferred, NewProductDraft $duplicate): NewProductDraft
    {
        if ($preferred->is($duplicate)) {
            return $preferred;
        }

        DB::transaction(function () use ($preferred, $duplicate): void {
            $preferred = $preferred->fresh();
            $duplicate = $duplicate->fresh();

            if (!$preferred || !$duplicate || $preferred->is($duplicate)) {
                return;
            }

            $updates = $this->mergedDraftValues($preferred, $duplicate);

            if (!empty($updates)) {
                NewProductDraft::query()
                    ->whereKey($preferred->id)
                    ->update($updates);
            }

            $approvalRows = DB::table('new_product_draft_approvals')
                ->where('new_product_draft_id', $duplicate->id)
                ->get();

            foreach ($approvalRows as $row) {
                DB::table('new_product_draft_approvals')->insertOrIgnore([
                    'new_product_draft_id' => $preferred->id,
                    'user_id' => $row->user_id,
                    'approval_version' => $row->approval_version,
                    'created_at' => $row->created_at,
                    'updated_at' => $row->updated_at,
                ]);
            }

            $assignmentRows = DB::table('new_product_draft_assignment_items')
                ->where('new_product_draft_id', $duplicate->id)
                ->get();

            foreach ($assignmentRows as $row) {
                $exists = DB::table('new_product_draft_assignment_items')
                    ->where('assignment_id', $row->assignment_id)
                    ->where('new_product_draft_id', $preferred->id)
                    ->exists();

                if ($exists) {
                    DB::table('new_product_draft_assignment_items')
                        ->where('id', $row->id)
                        ->delete();

                    continue;
                }

                DB::table('new_product_draft_assignment_items')
                    ->where('id', $row->id)
                    ->update([
                        'new_product_draft_id' => $preferred->id,
                        'handle' => $row->handle ?: $preferred->handle,
                        'title' => $row->title ?: $preferred->title,
                        'updated_at' => now(),
                    ]);
            }

            NewProductDraft::query()
                ->whereKey($duplicate->id)
                ->delete();
        });

        return $preferred->fresh() ?? $preferred;
    }

    /**
     * @return array<string, mixed>
     */
    private function mergedDraftValues(NewProductDraft $preferred, NewProductDraft $duplicate): array
    {
        $updates = [];
        $preferDuplicateValues = $duplicate->origin === NewProductDraft::ORIGIN_DRAFT_TOOL
            && $preferred->origin !== NewProductDraft::ORIGIN_DRAFT_TOOL;

        $mergeableFields = [
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
            'variant_inventory_policy',
            'variant_fulfillment_service',
            'payload',
            'origin',
            'approval_version',
            'created_by',
        ];

        foreach ($mergeableFields as $field) {
            $preferredValue = $preferred->getAttribute($field);
            $duplicateValue = $duplicate->getAttribute($field);

            if ($field === 'payload') {
                $preferredPayload = is_array($preferredValue) ? $preferredValue : [];
                $duplicatePayload = is_array($duplicateValue) ? $duplicateValue : [];
                $mergedPayload = $preferDuplicateValues
                    ? array_replace($preferredPayload, $duplicatePayload)
                    : array_replace($duplicatePayload, $preferredPayload);

                if ($mergedPayload !== $preferredPayload) {
                    $updates[$field] = $mergedPayload;
                }

                continue;
            }

            if ($field === 'origin') {
                $resolvedOrigin = $this->resolvedDraftOrigin($preferred, $duplicate);
                if ($resolvedOrigin !== null && $resolvedOrigin !== $preferredValue) {
                    $updates[$field] = $resolvedOrigin;
                }

                continue;
            }

            if ($field === 'approval_version') {
                $resolvedVersion = max((int) ($preferredValue ?? 1), (int) ($duplicateValue ?? 1));
                if ($resolvedVersion !== (int) ($preferredValue ?? 1)) {
                    $updates[$field] = $resolvedVersion;
                }

                continue;
            }

            if ($this->isEmptyDraftValue($duplicateValue)) {
                continue;
            }

            if ($preferDuplicateValues || $this->isEmptyDraftValue($preferredValue)) {
                if ($preferredValue !== $duplicateValue) {
                    $updates[$field] = $duplicateValue;
                }
            }
        }

        return $updates;
    }

    private function resolvedDraftOrigin(NewProductDraft $preferred, NewProductDraft $duplicate): ?string
    {
        $origins = array_values(array_filter([
            $preferred->origin,
            $duplicate->origin,
        ], fn ($value): bool => is_string($value) && trim($value) !== ''));

        if (in_array(NewProductDraft::ORIGIN_DRAFT_TOOL, $origins, true)) {
            return NewProductDraft::ORIGIN_DRAFT_TOOL;
        }

        if (in_array(NewProductDraft::ORIGIN_PRODUCT_MIRROR, $origins, true)) {
            return NewProductDraft::ORIGIN_PRODUCT_MIRROR;
        }

        return $origins[0] ?? null;
    }

    /**
     * @param array<string, mixed> $payload
     * @return array<string, mixed>
     */
    private function draftUpdatesForPayload(NewProductDraft $draft, array $payload): array
    {
        $updates = [];

        foreach ($payload as $key => $incomingValue) {
            if ($key === 'handle' || $key === 'shopify_id') {
                if (
                    ! $this->isEmptyDraftValue($incomingValue)
                    && (string) $draft->getAttribute($key) !== (string) $incomingValue
                ) {
                    $updates[$key] = $incomingValue;
                }

                continue;
            }

            if ($key === 'variant_inventory_qty' && $incomingValue === null) {
                if ($draft->getAttribute($key) !== null) {
                    $updates[$key] = null;
                }

                continue;
            }

            if ($this->isEmptyDraftValue($incomingValue)) {
                continue;
            }

            $currentValue = $draft->getAttribute($key);

            // SKU should always mirror the current product variant SKU.
            if ($key === 'sku') {
                if ((string) $currentValue !== (string) $incomingValue) {
                    $updates[$key] = $incomingValue;
                }

                continue;
            }

            if ($this->isEmptyDraftValue($currentValue)) {
                $updates[$key] = $incomingValue;
            }
        }

        return $updates;
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
