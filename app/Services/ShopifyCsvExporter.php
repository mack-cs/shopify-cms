<?php

namespace App\Services;

use App\Models\Import;
use App\Models\Product;
use App\Models\ShopifyRow;
use App\Models\Variant;
use App\Models\Image;
use Illuminate\Support\Facades\DB;
use League\Csv\Reader;
use League\Csv\Writer;
use SplTempFileObject;
use App\Services\RowKey;

final class ShopifyCsvExporter
{
    /**
     * Export a Shopify CSV for an import.
     * Scope:
     *  - 'all'
     *  - 'approved' (requires approvals logic, see below)
     */
    public function exportToString(Import $import, string $scope = 'all'): string
    {
        $headers = $import->headers ?? [];
        if (empty($headers)) {
            $templatePath = HeaderStore::latestTemplatePath();
            if ($templatePath === null || !is_file($templatePath)) {
                throw new \RuntimeException('Import headers missing; cannot export.');
            }

            $csv = Reader::createFromPath($templatePath);
            $csv->setHeaderOffset(0);
            $headers = $csv->getHeader();
            if (empty($headers)) {
                throw new \RuntimeException('Template headers missing; cannot export.');
            }
        }

        $writer = Writer::createFromFileObject(new SplTempFileObject());
        $writer->insertOne($headers);

        $rowsQuery = ShopifyRow::query()
            ->where('import_id', $import->id)
            ->orderBy('row_index');

        if ($scope === 'approved') {
            $approvedHandles = $this->approvedHandlesForImport($import->id);
            $rowsQuery->whereIn('handle', $approvedHandles);
        }

        // Preload normalized data
        $productsByHandle = Product::where('import_id', $import->id)->get()->keyBy('handle');
        $this->ensureCombinedRows($import->id, $headers, $productsByHandle->values());

        foreach ($rowsQuery->cursor() as $shopifyRow) {
            $exportRow = $shopifyRow->data;

            $handle = $shopifyRow->handle;
            $product = $handle ? ($productsByHandle[$handle] ?? null) : null;

            if ($product) {
                $desiredHandle = $product->desiredHandle();
                if ($desiredHandle !== null) {
                    $exportRow[HeaderStore::HANDLE] = $desiredHandle;
                }
            }

            if ($product && $shopifyRow->row_type === 'product_primary') {
                $this->overlayProductPrimary($exportRow, $product, $headers);
            }

            if ($product && $shopifyRow->row_type === 'variant') {
                $this->overlayVariant($exportRow, $product, $shopifyRow->variant_key, $headers);
            }

            if ($product && $shopifyRow->row_type === 'image') {
                $this->overlayImage($exportRow, $product, $shopifyRow->image_key, $headers);
            }

            // Write row in exact header order
            $writer->insertOne($this->rowValuesInHeaderOrder($exportRow, $headers));
        }

        return $writer->toString();
    }

    private function rowValuesInHeaderOrder(array $rowAssoc, array $headers): array
    {
        $vals = [];
        foreach ($headers as $h) {
            $vals[] = $rowAssoc[$h] ?? '';
        }
        return $vals;
    }

    private function overlayProductPrimary(array &$row, Product $product, array $headers): void
    {
        $this->setIfHeaderExists($row, $headers, HeaderStore::TITLE, $product->title);
        $this->setIfHeaderExists($row, $headers, HeaderStore::BODY_HTML, $product->body_html);
        $this->setIfHeaderExists($row, $headers, HeaderStore::VENDOR, $product->vendor);
        $this->setIfHeaderExists($row, $headers, HeaderStore::TAGS, $product->tags);
        $this->setIfHeaderExists($row, $headers, HeaderStore::TYPE, $product->type);
        $this->setIfHeaderExists($row, $headers, HeaderStore::PUBLISHED, $product->published);

        $this->setIfHeaderExists($row, $headers, HeaderStore::PRODUCT_CATEGORY, $product->product_category);
        $this->setIfHeaderExists($row, $headers, HeaderStore::GOOGLE_PRODUCT_CATEGORY, $product->google_product_category);

        $this->setIfHeaderExists(
            $row,
            $headers,
            HeaderStore::COLOR_METAFIELD,
            $this->normalizeColorExport($product->color_string)
        );

        $this->setIfHeaderExists($row, $headers, HeaderStore::STATUS, $product->status);
        $this->setIfHeaderExists($row, $headers, HeaderStore::SEO_TITLE, $product->seo_title);
        $this->setIfHeaderExists($row, $headers, HeaderStore::SEO_DESCRIPTION, $product->seo_description);
    }

    private function overlayVariant(array &$row, Product $product, ?string $variantKey, array $headers): void
    {
        if (!$variantKey) return;

        $variant = $product->variants()
            ->where('sku', $variantKey)
            ->first();

        // Fallback: try by option signature if SKU not found
        if (!$variant) {
            $variant = $product->variants()->first(); // simple fallback; refine later if needed
        }
        if (!$variant) return;

        $this->setIfHeaderExists($row, $headers, HeaderStore::VARIANT_SKU, $variant->sku);
        $this->setIfHeaderExists($row, $headers, HeaderStore::VARIANT_PRICE, $variant->price);
        $this->setIfHeaderExists($row, $headers, HeaderStore::VARIANT_COMPARE_AT, $variant->compare_at_price);
        $this->setIfHeaderExists($row, $headers, HeaderStore::VARIANT_BARCODE, $variant->barcode);

        $this->setIfHeaderExists($row, $headers, HeaderStore::OPTION1_NAME, $variant->option1_name);
        $this->setIfHeaderExists($row, $headers, HeaderStore::OPTION1_VALUE, $variant->option1_value);
        $this->setIfHeaderExists($row, $headers, HeaderStore::OPTION2_NAME, $variant->option2_name);
        $this->setIfHeaderExists($row, $headers, HeaderStore::OPTION2_VALUE, $variant->option2_value);
        $this->setIfHeaderExists($row, $headers, HeaderStore::OPTION3_NAME, $variant->option3_name);
        $this->setIfHeaderExists($row, $headers, HeaderStore::OPTION3_VALUE, $variant->option3_value);
    }

    private function overlayImage(array &$row, Product $product, ?string $imageKey, array $headers): void
    {
        // imageKey is src|position or src
        if (!$imageKey) return;

        $parts = explode('|', $imageKey);
        $src = $parts[0] ?? null;
        $pos = $parts[1] ?? null;

        $imgQuery = $product->images()->where('src', $src);
        if ($pos !== null) {
            $imgQuery->where('position', (int)$pos);
        }
        $image = $imgQuery->first();
        if (!$image) return;

        $this->setIfHeaderExists($row, $headers, HeaderStore::IMAGE_SRC, $image->src);
        $this->setIfHeaderExists($row, $headers, HeaderStore::IMAGE_POSITION, $image->position);
        $this->setIfHeaderExists($row, $headers, HeaderStore::IMAGE_ALT_TEXT, $image->alt_text);
    }

    private function setIfHeaderExists(array &$row, array $headers, string $header, mixed $value): void
    {
        if (!in_array($header, $headers, true)) return;
        if ($value === null) return;
        $row[$header] = (string)$value;
    }

    private function normalizeColorExport(?string $value): ?string
    {
        if ($value === null) {
            return null;
        }

        $trimmed = trim($value);
        if ($trimmed === '') {
            return null;
        }

        $noQuotes = str_replace('"', '', $trimmed);
        return str_replace(',', ';', $noQuotes);
    }

    // private function approvedHandlesForImport(int $importId): array
    // {
    //     // Skeleton: you’ll implement approvals logic.
    //     // SELECT handles where count(distinct user_id) >= 2
    //     return DB::table('approvals')
    //         ->join('products', 'approvals.product_id', '=', 'products.id')
    //         ->where('products.import_id', $importId)
    //         ->select('products.handle')
    //         ->groupBy('products.handle')
    //         ->havingRaw('COUNT(DISTINCT approvals.user_id) >= 2')
    //         ->pluck('products.handle')
    //         ->all();
    // }

    private function approvedHandlesForImport(int $importId): array
    {
        return DB::table('approvals')
            ->join('products', 'approvals.product_id', '=', 'products.id')
            ->where('products.import_id', $importId)
            ->whereColumn('approvals.approval_version', 'products.approval_version')
            ->select('products.handle')
            ->groupBy('products.handle')
            ->havingRaw('COUNT(DISTINCT approvals.user_id) >= 2')
            ->pluck('products.handle')
            ->all();
    }

    private function ensureCombinedRows(int $importId, array $headers, $products): void
    {
        if (empty($headers)) {
            return;
        }

        foreach ($products as $product) {
            $variants = $product->variants()->orderBy('id')->get();
            $images = $product->images()->orderBy('position')->get();

            $rowCount = max($variants->count(), $images->count(), 1);
            $minRowIndex = (int) (ShopifyRow::where('import_id', $importId)
                ->where('handle', $product->handle)
                ->min('row_index') ?? 0);
            if ($minRowIndex === 0) {
                $minRowIndex = (int) (ShopifyRow::where('import_id', $importId)->max('row_index') ?? 0) + 1;
            }

            $primaryRow = ShopifyRow::where('import_id', $importId)
                ->where('handle', $product->handle)
                ->where('row_type', 'product_primary')
                ->first();

            $basePrimary = $primaryRow?->data ?? array_fill_keys($headers, '');
            if (array_key_exists(HeaderStore::HANDLE, $basePrimary)) {
                $basePrimary[HeaderStore::HANDLE] = $product->handle;
            }

            ShopifyRow::where('import_id', $importId)
                ->where('handle', $product->handle)
                ->whereIn('row_type', ['variant', 'image'])
                ->delete();

            for ($i = 0; $i < $rowCount; $i++) {
                $variant = $variants[$i] ?? null;
                $image = $images[$i] ?? null;

                $rowData = $i === 0 ? $basePrimary : array_fill_keys($headers, '');
                if (array_key_exists(HeaderStore::HANDLE, $rowData)) {
                    $rowData[HeaderStore::HANDLE] = $product->handle;
                }

                if ($variant) {
                    $this->applyVariantToRowData($rowData, $variant);
                }
                if ($image) {
                    $this->applyImageToRowData($rowData, $image);
                }

                $rowType = $i === 0 ? 'product_primary' : ($variant ? 'variant' : 'image');
                $variantKey = $variant ? RowKey::variantKey($rowData) : null;
                $imageKey = $image ? RowKey::imageKey($rowData) : null;

                if ($i === 0 && $primaryRow) {
                    $primaryRow->update([
                        'row_index' => $minRowIndex,
                        'row_type' => 'product_primary',
                        'variant_key' => $variantKey,
                        'image_key' => $imageKey,
                        'data' => $rowData,
                    ]);
                } elseif ($i === 0 && !$primaryRow) {
                    ShopifyRow::create([
                        'import_id' => $importId,
                        'row_index' => $minRowIndex,
                        'handle' => $product->handle,
                        'row_type' => 'product_primary',
                        'variant_key' => $variantKey,
                        'image_key' => $imageKey,
                        'data' => $rowData,
                    ]);
                } else {
                    ShopifyRow::create([
                        'import_id' => $importId,
                        'row_index' => $minRowIndex + $i,
                        'handle' => $product->handle,
                        'row_type' => $rowType,
                        'variant_key' => $variantKey,
                        'image_key' => $imageKey,
                        'data' => $rowData,
                    ]);
                }
            }
        }
    }

    private function applyVariantToRowData(array &$rowData, Variant $variant): void
    {
        $rowData[HeaderStore::VARIANT_SKU] = $variant->sku ?? '';
        $rowData[HeaderStore::VARIANT_BARCODE] = $variant->barcode ?? '';
        $rowData[HeaderStore::OPTION1_NAME] = $variant->option1_name ?? '';
        $rowData[HeaderStore::OPTION1_VALUE] = $variant->option1_value ?? '';
        $rowData[HeaderStore::OPTION2_NAME] = $variant->option2_name ?? '';
        $rowData[HeaderStore::OPTION2_VALUE] = $variant->option2_value ?? '';
        $rowData[HeaderStore::OPTION3_NAME] = $variant->option3_name ?? '';
        $rowData[HeaderStore::OPTION3_VALUE] = $variant->option3_value ?? '';
        $rowData[HeaderStore::VARIANT_PRICE] = $variant->price ?? '';
        $rowData[HeaderStore::VARIANT_COMPARE_AT] = $variant->compare_at_price ?? '';

        $rowData['Variant Inventory Qty'] = $variant->inventory_tracked === false
            ? ''
            : ($variant->inventory_qty ?? '');
        $rowData['Variant Inventory Policy'] = $variant->inventory_policy ?? '';
        $rowData['Variant Requires Shipping'] = $variant->requires_shipping ? 'true' : 'false';
        $rowData['Variant Taxable'] = $variant->taxable ? 'true' : 'false';
        $rowData['Variant Grams'] = $variant->weight ?? '';
        $rowData['Variant Weight Unit'] = $variant->weight_unit ?? '';
    }

    private function applyImageToRowData(array &$rowData, Image $image): void
    {
        $rowData[HeaderStore::IMAGE_SRC] = $image->src ?? '';
        $rowData[HeaderStore::IMAGE_POSITION] = $image->position ?? '';
        $rowData[HeaderStore::IMAGE_ALT_TEXT] = $image->alt_text ?? '';
    }

}
