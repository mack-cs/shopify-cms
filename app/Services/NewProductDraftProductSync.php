<?php

namespace App\Services;

use App\Models\Import;
use App\Models\NewProductDraft;
use App\Models\Product;
use App\Models\ShopifyRow;
use App\Models\Variant;
use App\Services\HeaderStore;
use App\Services\Normalizer;
use Illuminate\Support\Collection;

final class NewProductDraftProductSync
{
    public function syncToExistingProduct(
        NewProductDraft $draft,
        bool $ensureApprovalReset = true,
        ?array $attributes = null
    ): bool
    {
        if (!$draft->handle && !$draft->shopify_id) {
            return false;
        }

        $product = $this->findExistingProduct($draft);

        if (!$product) {
            return false;
        }

        $initialApprovalVersion = (int) ($product->approval_version ?? 1);
        $attributes = $this->normalizeAttributes($attributes);
        $data = $this->mapDraftToProduct($draft, $attributes);

        if (!empty($data)) {
            $product->fill($data)->save();
        }
        $this->syncVariantFromDraft($product, $draft, $attributes);
        $this->syncCostPerItemRow($product, $draft, $attributes);
        $this->syncShopifyRowFieldsFromDraft($product, $draft, $attributes);

        $product->refresh();
        if ($ensureApprovalReset && (int) ($product->approval_version ?? 1) === $initialApprovalVersion) {
            Product::withoutEvents(function () use ($product): void {
                $product->forceFill([
                    'approval_version' => ((int) ($product->approval_version ?? 1)) + 1,
                ])->save();
            });
        }

        app(Normalizer::class)->recalculateErrorsForProduct($product);

        return true;
    }

    /**
     * @param Collection<int, NewProductDraft>|null $drafts
     * @return array{updated:int, created:int, skipped_unapproved:int, skipped_missing_handle:int, skipped_missing_import:int}
     */
    public function syncApprovedDrafts(?Collection $drafts = null): array
    {
        $updated = 0;
        $created = 0;
        $skippedUnapproved = 0;
        $skippedMissingHandle = 0;
        $skippedMissingImport = 0;

        $drafts = $drafts ?? NewProductDraft::query()
            ->where(function ($query): void {
                $query->whereNotNull('handle')
                    ->where('handle', '!=', '')
                    ->orWhere(function ($idQuery): void {
                        $idQuery->whereNotNull('shopify_id')
                            ->where('shopify_id', '!=', '');
                    });
            })
            ->get();

        foreach ($drafts as $draft) {
            if (!$draft instanceof NewProductDraft) {
                continue;
            }

            if (!$draft->handle && !$draft->shopify_id) {
                $skippedMissingHandle++;
                continue;
            }

            if (!$draft->isApprovedByTwo()) {
                $skippedUnapproved++;
                continue;
            }

            $product = $this->findExistingProduct($draft);

            $data = $this->mapDraftToProduct($draft);

            if ($product) {
                $this->syncToExistingProduct($draft, ensureApprovalReset: false);
                $updated++;
                continue;
            }

            if (!$draft->handle) {
                $skippedMissingHandle++;
                continue;
            }

            $import = Import::where('is_current', true)->first();
            if (!$import) {
                $skippedMissingImport++;
                continue;
            }

            Product::create(array_merge(
                ['import_id' => $import->id, 'handle' => $draft->handle],
                $data
            ));
            $created++;
        }

        return [
            'updated' => $updated,
            'created' => $created,
            'skipped_unapproved' => $skippedUnapproved,
            'skipped_missing_handle' => $skippedMissingHandle,
            'skipped_missing_import' => $skippedMissingImport,
        ];
    }

    private function findExistingProduct(NewProductDraft $draft): ?Product
    {
        $shopifyId = trim((string) ($draft->shopify_id ?? ''));

        if ($shopifyId !== '') {
            $product = Product::query()
                ->where('shopify_id', $shopifyId)
                ->first();

            if ($product) {
                return $product;
            }
        }

        $handle = trim((string) ($draft->handle ?? ''));

        if ($handle === '') {
            return null;
        }

        return Product::query()
            ->where('handle', $handle)
            ->first();
    }

    /**
     * @return array<string, mixed>
     */
    private function mapDraftToProduct(NewProductDraft $draft, ?array $attributes = null): array
    {
        $data = [
            'shopify_id' => $draft->shopify_id,
            'title' => $draft->title,
            'body_html' => $draft->body_html,
            'vendor' => $draft->vendor,
            'tags' => $draft->tags,
            'type' => $draft->type,
            'product_category' => $draft->product_category,
            'google_product_category' => $draft->google_product_category,
            'status' => $draft->status,
            'published' => $draft->published,
            'color_string' => $draft->color_string,
            'uvp_short_paragraph' => $draft->uvp_short_paragraph,
            'batch' => $draft->batch,
        ];

        if ($attributes === null) {
            return array_filter($data, static fn ($value) => $value !== null);
        }

        $selected = [];
        foreach ($attributes as $attribute) {
            if (!array_key_exists($attribute, $data)) {
                continue;
            }

            $selected[$attribute] = $data[$attribute];
        }

        return $selected;
    }

    private function syncVariantFromDraft(Product $product, NewProductDraft $draft, ?array $attributes = null): void
    {
        $variant = Variant::where('product_id', $product->id)->orderBy('id')->first();
        if (!$variant) {
            return;
        }

        $updates = [];
        if ($this->shouldSyncDraftAttribute('sku', $attributes, $draft->sku)) {
            $updates['sku'] = $draft->sku;
        }
        if ($this->shouldSyncDraftAttribute('variant_price', $attributes, $draft->variant_price)) {
            $updates['price'] = $draft->variant_price;
        }
        if ($this->shouldSyncDraftAttribute('variant_compare_at_price', $attributes, $draft->variant_compare_at_price)) {
            $updates['compare_at_price'] = $draft->variant_compare_at_price;
        }
        if ($this->shouldSyncDraftAttribute('variant_inventory_qty', $attributes, $draft->variant_inventory_qty)) {
            $updates['inventory_qty'] = $draft->variant_inventory_qty;
        }

        if (!empty($updates)) {
            $variant->update($updates);
        }
    }

    private function syncCostPerItemRow(Product $product, NewProductDraft $draft, ?array $attributes = null): void
    {
        $syncMaterialCost = $this->shouldSyncDraftAttribute('material_cost', $attributes, $draft->material_cost);
        $syncInventory = $this->shouldSyncDraftAttribute('variant_inventory_qty', $attributes, $draft->variant_inventory_qty);

        if (!$syncMaterialCost && !$syncInventory) {
            return;
        }

        $row = ShopifyRow::where('import_id', $product->import_id)
            ->where('handle', $product->handle)
            ->where('row_type', 'product_primary')
            ->first();

        if (!$row) {
            return;
        }

        if ($syncMaterialCost) {
            $row->set(HeaderStore::COST_PER_ITEM, (string) $draft->material_cost);
        }
        if ($syncInventory) {
            $row->set(HeaderStore::VARIANT_INVENTORY_QTY, (string) $draft->variant_inventory_qty);
        }
        $row->save();
    }

    private function syncShopifyRowFieldsFromDraft(Product $product, NewProductDraft $draft, ?array $attributes = null): void
    {
        $row = ShopifyRow::where('import_id', $product->import_id)
            ->where('handle', $product->handle)
            ->where('row_type', 'product_primary')
            ->first();

        if (!$row) {
            return;
        }

        $updates = [];

        $this->addRowUpdate($updates, HeaderStore::MATERIAL_COST, $draft->material_cost, 'material_cost', $attributes);
        $this->addRowUpdate($updates, HeaderStore::JEWELRY_MATERIAL, $draft->jewelry_material, 'jewelry_material', $attributes);
        $this->addRowUpdate($updates, HeaderStore::PRODUCT_MATERIALS, $draft->product_materials, 'product_materials', $attributes);
        $this->addRowUpdate($updates, HeaderStore::MATERIALS_AND_DIMENSIONS, $draft->materials_and_dimensions, 'materials_and_dimensions', $attributes);

        if ($this->shouldSyncDraftAttribute('product_design', $attributes, $draft->product_design)) {
            foreach (HeaderStore::designHeaders() as $designHeader) {
                $updates[$designHeader] = '';
            }

            $resolvedDesignHeader = HeaderStore::designHeaderForTypeAndTags($draft->type, $draft->tags);
            if ($resolvedDesignHeader !== null) {
                $updates[$resolvedDesignHeader] = trim((string) ($draft->product_design ?? ''));
            }
        }

        if ($this->shouldSyncDraftAttribute('metal', $attributes, $draft->metal)) {
            $updates[HeaderStore::PRODUCT_METALS] = trim((string) ($draft->metal ?? ''));
        }
        if ($this->shouldSyncDraftAttribute('colour_style', $attributes, $draft->colour_style)) {
            $updates[HeaderStore::PATTERN_CATEGORY] = trim((string) ($draft->colour_style ?? ''));
        }
        $this->addRowUpdate($updates, HeaderStore::SIZE, $draft->size, 'size', $attributes);
        $this->addRowUpdate($updates, HeaderStore::SIBLINGS, $draft->siblings, 'siblings', $attributes);
        if (
            $this->shouldSyncDraftAttribute('siblings_collection_name', $attributes, $draft->title)
            || $this->shouldSyncDraftAttribute('title', $attributes, $draft->title)
        ) {
            $updates[HeaderStore::SIBLINGS_COLLECTION_NAME] = trim((string) ($draft->title ?? ''));
        }
        $this->addRowUpdate($updates, HeaderStore::SIBLING_COLLECTION, $draft->sibling_collection, 'sibling_collection', $attributes);
        $this->addRowUpdate($updates, HeaderStore::UVP_SHORT_PARAGRAPH, $draft->uvp_short_paragraph, 'uvp_short_paragraph', $attributes);
        $this->addRowUpdate($updates, HeaderStore::COMPLEMENTARY_PRODUCTS, $draft->complementary_products, 'complementary_products', $attributes);

        if ($this->shouldSyncDraftAttribute('payload', $attributes, $draft->payload)) {
            foreach ($this->extraDraftPayloadHeaders($product) as $header) {
                if (!array_key_exists($header, $updates)) {
                    $updates[$header] = '';
                }
            }
            foreach ($this->payloadRowUpdates($draft, $product) as $header => $value) {
                if (!array_key_exists($header, $updates)) {
                    $updates[$header] = $value;
                }
            }
        }

        if (empty($updates)) {
            return;
        }

        foreach ($updates as $header => $value) {
            $row->set($header, $value);
        }

        $row->save();
    }

    private function addRowUpdate(
        array &$updates,
        string $header,
        mixed $value,
        string $attribute,
        ?array $attributes = null
    ): void
    {
        if (!$this->shouldSyncDraftAttribute($attribute, $attributes, $value)) {
            return;
        }

        $updates[$header] = is_scalar($value) ? (string) $value : '';
    }

    /**
     * @return array<int, string>
     */
    private function extraDraftPayloadHeaders(Product $product): array
    {
        return HeaderStore::extraProductHeadersForDraftWorkflow($product->import?->headers ?? []);
    }

    /**
     * @return array<string, string>
     */
    private function extraDraftPayloadUpdates(NewProductDraft $draft, Product $product): array
    {
        $payload = is_array($draft->payload) ? $draft->payload : [];
        $allowed = array_flip($this->payloadAllowedHeaders($product));

        $updates = [];
        foreach ($payload as $header => $value) {
            if (!is_string($header) || !isset($allowed[$header])) {
                continue;
            }

            if (is_array($value)) {
                $value = implode('; ', array_values(array_filter(array_map(
                    fn (mixed $item): string => trim((string) $item),
                    $value
                ))));
            }

            $updates[$header] = is_scalar($value) ? trim((string) $value) : '';
        }

        return $updates;
    }

    /**
     * @return array<string, string>
     */
    private function payloadRowUpdates(NewProductDraft $draft, Product $product): array
    {
        return $this->extraDraftPayloadUpdates($draft, $product);
    }

    /**
     * @return array<int, string>
     */
    private function payloadAllowedHeaders(Product $product): array
    {
        $headers = $product->import?->headers ?? [];
        if (!empty($headers)) {
            return array_values(array_filter(array_map(
                static fn (mixed $header): string => trim((string) $header),
                $headers
            ), static fn (string $header): bool => $header !== ''));
        }

        return HeaderStore::knownHeaders();
    }

    private function shouldSyncDraftAttribute(string $attribute, ?array $attributes, mixed $value): bool
    {
        if ($attributes === null) {
            return $value !== null;
        }

        return in_array($attribute, $attributes, true);
    }

    /**
     * @param array<int, mixed>|null $attributes
     * @return array<int, string>|null
     */
    private function normalizeAttributes(?array $attributes): ?array
    {
        if ($attributes === null) {
            return null;
        }

        $normalized = [];
        foreach ($attributes as $attribute) {
            if (!is_string($attribute)) {
                continue;
            }

            $attribute = trim($attribute);
            if ($attribute === '') {
                continue;
            }

            $normalized[$attribute] = $attribute;
        }

        return array_values($normalized);
    }
}
