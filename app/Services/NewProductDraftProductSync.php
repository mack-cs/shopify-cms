<?php

namespace App\Services;

use App\Models\Import;
use App\Models\NewProductDraft;
use App\Models\Product;
use App\Models\ShopifyRow;
use App\Models\Variant;
use App\Services\HeaderStore;
use Illuminate\Support\Collection;

final class NewProductDraftProductSync
{
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
            ->whereNotNull('handle')
            ->where('handle', '!=', '')
            ->get();

        foreach ($drafts as $draft) {
            if (!$draft instanceof NewProductDraft) {
                continue;
            }

            if (!$draft->handle) {
                $skippedMissingHandle++;
                continue;
            }

            if (!$draft->isApprovedByTwo()) {
                $skippedUnapproved++;
                continue;
            }

            $product = Product::query()
                ->where('handle', $draft->handle)
                ->first();

            $data = $this->mapDraftToProduct($draft);

            if ($product) {
                $product->fill($data)->save();
                $this->syncVariantFromDraft($product, $draft);
                $this->syncCostPerItemRow($product, $draft);
                $this->syncShopifyRowFieldsFromDraft($product, $draft);
                $updated++;
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

    /**
     * @return array<string, mixed>
     */
    private function mapDraftToProduct(NewProductDraft $draft): array
    {
        return array_filter([
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
            'batch' => $draft->batch,
        ], static fn ($value) => $value !== null);
    }

    private function syncVariantFromDraft(Product $product, NewProductDraft $draft): void
    {
        $variant = Variant::where('product_id', $product->id)->orderBy('id')->first();
        if (!$variant) {
            return;
        }

        $updates = [];
        if ($draft->sku) {
            $updates['sku'] = $draft->sku;
        }
        if ($draft->variant_price !== null) {
            $updates['price'] = $draft->variant_price;
        }
        if ($draft->variant_compare_at_price !== null) {
            $updates['compare_at_price'] = $draft->variant_compare_at_price;
        }
        if ($draft->variant_inventory_qty !== null) {
            $updates['inventory_qty'] = $draft->variant_inventory_qty;
        }

        if (!empty($updates)) {
            $variant->update($updates);
        }
    }

    private function syncCostPerItemRow(Product $product, NewProductDraft $draft): void
    {
        if ($draft->variant_price === null && $draft->variant_inventory_qty === null) {
            return;
        }

        $row = ShopifyRow::where('import_id', $product->import_id)
            ->where('handle', $product->handle)
            ->where('row_type', 'product_primary')
            ->first();

        if (!$row) {
            return;
        }

        if ($draft->variant_price !== null) {
            $row->set(HeaderStore::COST_PER_ITEM, (string) $draft->variant_price);
        }
        if ($draft->variant_inventory_qty !== null) {
            $row->set(HeaderStore::VARIANT_INVENTORY_QTY, (string) $draft->variant_inventory_qty);
        }
        $row->save();
    }

    private function syncShopifyRowFieldsFromDraft(Product $product, NewProductDraft $draft): void
    {
        $row = ShopifyRow::where('import_id', $product->import_id)
            ->where('handle', $product->handle)
            ->where('row_type', 'product_primary')
            ->first();

        if (!$row) {
            return;
        }

        $updates = [];

        $this->addRowUpdate($updates, HeaderStore::MATERIAL_COST, $draft->material_cost);
        $this->addRowUpdate($updates, HeaderStore::JEWELRY_MATERIAL, $draft->jewelry_material);
        $this->addRowUpdate($updates, HeaderStore::PRODUCT_MATERIALS, $draft->product_materials);
        $this->addRowUpdate($updates, HeaderStore::MATERIALS_AND_DIMENSIONS, $draft->materials_and_dimensions);
        $this->addRowUpdate($updates, HeaderStore::BRACELET_DESIGN, $draft->product_design);
        $this->addRowUpdate($updates, HeaderStore::PRODUCT_METALS, $draft->metal);
        $this->addRowUpdate($updates, HeaderStore::PATTERN_CATEGORY, $draft->colour_style);
        $this->addRowUpdate($updates, HeaderStore::SIZE, $draft->size);
        $this->addRowUpdate($updates, HeaderStore::SIBLINGS, $draft->siblings);
        $this->addRowUpdate($updates, HeaderStore::SIBLINGS_COLLECTION_NAME, $draft->siblings_collection_name);
        $this->addRowUpdate($updates, HeaderStore::COMPLEMENTARY_PRODUCTS, $draft->complementary_products);

        if (empty($updates)) {
            return;
        }

        foreach ($updates as $header => $value) {
            $row->set($header, $value);
        }

        $row->save();
    }

    private function addRowUpdate(array &$updates, string $header, mixed $value): void
    {
        if ($value === null) {
            return;
        }

        $stringValue = is_scalar($value) ? (string) $value : null;
        if ($stringValue === null) {
            return;
        }

        $updates[$header] = $stringValue;
    }
}
