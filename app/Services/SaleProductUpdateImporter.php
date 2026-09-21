<?php

namespace App\Services;

use App\Models\SaleImportBatch;
use App\Models\SaleImportItem;
use App\Models\SaleProductUpdate;
use App\Models\NewProductDraft;
use App\Models\Product;
use App\Models\Variant;
use Illuminate\Support\Facades\DB;
use League\Csv\Reader;

final class SaleProductUpdateImporter
{
    public function __construct(
        private readonly SaleTagService $saleTags,
    ) {}

    /**
     * @return array{
     *   batch_id:int,
     *   total:int,
     *   matched:int,
     *   unmatched:int,
     *   failed:int,
     *   pending:int,
     *   unmatched_skus:array<int,string>,
     *   failed_skus:array<int,string>
     * }
     */
    public function importFromPath(string $absolutePath, ?int $userId = null, ?string $filename = null): array
    {
        $csv = Reader::createFromPath($absolutePath);
        $csv->setHeaderOffset(0);

        $batch = SaleImportBatch::create([
            'filename' => $filename ? basename($filename) : basename($absolutePath),
            'status' => SaleImportBatch::STATUS_COMPLETED,
            'created_by' => $userId,
        ]);

        $summary = [
            'batch_id' => $batch->id,
            'total' => 0,
            'matched' => 0,
            'unmatched' => 0,
            'failed' => 0,
            'pending' => 0,
            'unmatched_skus' => [],
            'failed_skus' => [],
        ];

        try {
            DB::transaction(function () use ($csv, $batch, &$summary): void {
                foreach ($csv->getRecords() as $row) {
                    $summary['total']++;
                    $normalized = $this->normalizedRow($row);
                    $match = $this->matchVariant($normalized);
                    $identifier = $match['identifier'];

                    $salePrice = $this->decimalString($normalized['sale_price'] ?? $normalized['price'] ?? null);
                    if ($salePrice === null) {
                        $this->recordImportItem($batch, $normalized, $identifier, SaleImportItem::STATUS_FAILED, 'Missing or invalid sale price.');
                        $summary['failed']++;
                        $summary['failed_skus'][] = $identifier;
                        continue;
                    }

                    $variant = $match['variant'];
                    if (!$variant) {
                        $this->recordImportItem($batch, $normalized, $identifier, SaleImportItem::STATUS_UNMATCHED, $match['message']);
                        $summary['unmatched']++;
                        $summary['unmatched_skus'][] = $identifier;
                        continue;
                    }

                    $product = $variant->product;
                    if (!$product) {
                        $this->recordImportItem($batch, $normalized, $identifier, SaleImportItem::STATUS_UNMATCHED, 'Matched identifier has no linked product.');
                        $summary['unmatched']++;
                        $summary['unmatched_skus'][] = $identifier;
                        continue;
                    }

                    $sku = $this->saleUpdateSku($variant, $identifier);

                    $importedOldPrice = $this->decimalString($normalized['old_price'] ?? null);
                    $currentPrice = $this->decimalString($variant->price);
                    $compareAt = $this->decimalString(
                        $normalized['compare_at_price']
                            ?? $normalized['old_price']
                            ?? $variant->price
                            ?? null
                    );

                    if ($compareAt === null) {
                        $this->recordImportItem($batch, $normalized, $sku, SaleImportItem::STATUS_FAILED, 'Missing compare-at price.');
                        $summary['failed']++;
                        $summary['failed_skus'][] = $sku;
                        continue;
                    }

                    if ((float) $salePrice >= (float) $compareAt) {
                        $this->recordImportItem($batch, $normalized, $sku, SaleImportItem::STATUS_FAILED, 'Sale price must be lower than compare-at price.');
                        $summary['failed']++;
                        $summary['failed_skus'][] = $sku;
                        continue;
                    }

                    $preparedTags = $this->preparedSaleTags((string) ($product->tags ?? ''), $product->type);
                    $payload = [
                        'sku' => $sku,
                        'product_id' => $product->id,
                        'variant_id' => $variant->id,
                        'shopify_product_id' => $product->shopify_id,
                        'shopify_variant_id' => $variant->shopify_id,
                        'current_price' => $currentPrice,
                        'imported_old_price' => $importedOldPrice,
                        'sale_price' => $salePrice,
                        'compare_at_price' => $compareAt,
                        'existing_tags' => $product->tags,
                        'prepared_tags' => $preparedTags,
                        'shopify_updates' => [
                            'tags' => TagNormalizer::parseTokens($preparedTags),
                            'variant' => [
                                'sku' => $sku,
                                'price' => $salePrice,
                                'compareAtPrice' => $compareAt,
                            ],
                        ],
                    ];

                    $update = SaleProductUpdate::query()
                        ->where('product_id', $product->id)
                        ->where('variant_id', $variant->id)
                        ->whereIn('status', [
                            SaleProductUpdate::STATUS_PENDING,
                            SaleProductUpdate::STATUS_APPROVED,
                            SaleProductUpdate::STATUS_FAILED,
                            SaleProductUpdate::STATUS_CANCELLED,
                        ])
                        ->latest('id')
                        ->first();

                    $attributes = [
                        'sale_import_batch_id' => $batch->id,
                        'product_id' => $product->id,
                        'variant_id' => $variant->id,
                        'sku' => $sku,
                        'status' => SaleProductUpdate::STATUS_PENDING,
                        'current_price' => $currentPrice,
                        'imported_old_price' => $importedOldPrice,
                        'sale_price' => $salePrice,
                        'compare_at_price' => $compareAt,
                        'existing_tags' => $product->tags,
                        'prepared_tags' => $preparedTags,
                        'approved_at' => null,
                        'approved_by' => null,
                        'scheduled_job_id' => null,
                        'scheduled_at' => null,
                        'pushed_at' => null,
                        'metadata' => $payload,
                        'error_message' => null,
                    ];

                    if ($update instanceof SaleProductUpdate) {
                        $update->fill($attributes)->save();
                    } else {
                        SaleProductUpdate::create($attributes);
                    }

                    $this->recordImportItem($batch, $normalized, $sku, SaleImportItem::STATUS_MATCHED, 'Sale update staged.', [
                        'product_id' => $product->id,
                        'variant_id' => $variant->id,
                        'old_price' => $importedOldPrice,
                        'compare_at_price' => $compareAt,
                        'sale_price' => $salePrice,
                        'payload' => $payload,
                    ]);

                    $summary['matched']++;
                    $summary['pending']++;
                }

                $batch->update([
                    'total_rows' => $summary['total'],
                    'matched_count' => $summary['matched'],
                    'unmatched_count' => $summary['unmatched'],
                    'failed_count' => $summary['failed'],
                ]);
            });
        } catch (\Throwable $e) {
            $batch->update([
                'status' => SaleImportBatch::STATUS_FAILED,
                'total_rows' => $summary['total'],
                'matched_count' => $summary['matched'],
                'unmatched_count' => $summary['unmatched'],
                'failed_count' => $summary['failed'],
                'error_message' => $e->getMessage(),
            ]);
            logger()->error('Sale product update import failed', [
                'sale_import_batch_id' => $batch->id,
                'error' => $e->getMessage(),
            ]);

            throw $e;
        }

        logger()->info('Sale product update import completed', $summary);

        return $summary;
    }

    /**
     * @param array<string, mixed> $row
     * @return array<string, string>
     */
    private function normalizedRow(array $row): array
    {
        $map = [
            'draft id' => 'draft_id',
            'new product draft id' => 'draft_id',
            'handle' => 'handle',
            'product id' => 'product_id',
            'shopify id' => 'shopify_product_id',
            'product shopify id' => 'shopify_product_id',
            'shopify product id' => 'shopify_product_id',
            'shopify gid' => 'shopify_product_id',
            'shopify variant id' => 'shopify_variant_id',
            'variant shopify id' => 'shopify_variant_id',
            'sku' => 'sku',
            'old price current price' => 'old_price',
            'old price' => 'old_price',
            'current price' => 'old_price',
            'price' => 'price',
            'variant price' => 'price',
            'compare to price' => 'compare_at_price',
            'compare at price' => 'compare_at_price',
            'compare at pric' => 'compare_at_price',
            'compare at' => 'compare_at_price',
            'compare price' => 'compare_at_price',
            'compare price stricked out price' => 'compare_at_price',
            'sale price' => 'sale_price',
            'new sale price' => 'sale_price',
            'sale' => 'sale_price',
        ];

        $normalized = [];
        foreach ($row as $header => $value) {
            $key = $map[$this->normalizeHeader((string) $header)] ?? null;
            if ($key === null) {
                continue;
            }

            $normalized[$key] = trim((string) $value);
        }

        return $normalized;
    }

    /**
     * @param array<string, string> $normalized
     * @return array{variant:?Variant,identifier:string,message:string}
     */
    private function matchVariant(array $normalized): array
    {
        $sku = trim((string) ($normalized['sku'] ?? ''));
        if ($sku !== '') {
            $product = $this->productForRow($normalized);
            if ($product instanceof Product) {
                $variant = $this->variantForSkuOnProduct($product, $sku);

                return [
                    'variant' => $variant,
                    'identifier' => $sku,
                    'message' => 'No active variant with SKU ' . $sku . ' matched the identified product.',
                ];
            }

            $matches = $this->variantsForSku($sku);
            if ($matches->count() === 1) {
                return [
                    'variant' => $matches->first(),
                    'identifier' => $sku,
                    'message' => 'No local product variant matched SKU ' . $sku . '.',
                ];
            }

            if ($matches->count() > 1) {
                return [
                    'variant' => null,
                    'identifier' => $sku,
                    'message' => 'Multiple local variants matched SKU ' . $sku . '. Add Shopify ID, Product ID, Handle, or Shopify Variant ID to choose the correct product variant.',
                ];
            }

            return [
                'variant' => null,
                'identifier' => $sku,
                'message' => 'No local product variant matched SKU ' . $sku . '.',
            ];
        }

        $shopifyVariantId = $this->normalizeShopifyGid($normalized['shopify_variant_id'] ?? null, 'ProductVariant');
        if ($shopifyVariantId !== null) {
            return [
                'variant' => $this->variantForShopifyId($shopifyVariantId),
                'identifier' => $shopifyVariantId,
                'message' => 'No local product variant matched Shopify variant ID ' . $shopifyVariantId . '.',
            ];
        }

        $product = $this->productForRow($normalized);
        if ($product instanceof Product) {
            $variant = $this->singleVariantForProduct($product);
            $message = $variant instanceof Variant
                ? 'Matched product by identifier.'
                : 'Matched product has multiple active variants. Include SKU or Shopify Variant ID so the sale update targets the correct variant.';

            return [
                'variant' => $variant,
                'identifier' => $this->rowIdentifier($normalized, $product),
                'message' => $message,
            ];
        }

        $identifier = $this->rowIdentifier($normalized);

        return [
            'variant' => null,
            'identifier' => $identifier,
            'message' => 'No local product matched ' . $identifier . '.',
        ];
    }

    private function recordImportItem(
        SaleImportBatch $batch,
        array $normalized,
        string $sku,
        string $status,
        string $message,
        array $extra = []
    ): void {
        SaleImportItem::create(array_merge([
            'sale_import_batch_id' => $batch->id,
            'sku' => $sku,
            'old_price' => $this->decimalString($normalized['old_price'] ?? null),
            'compare_at_price' => $this->decimalString($normalized['compare_at_price'] ?? null),
            'sale_price' => $this->decimalString($normalized['sale_price'] ?? null),
            'status' => $status,
            'message' => $message,
            'payload' => $normalized,
        ], $extra));
    }

    private function variantsForSku(string $sku)
    {
        $normalized = strtolower(trim($sku));

        return Variant::query()
            ->with('product')
            ->whereRaw('LOWER(TRIM(sku)) = ?', [$normalized])
            ->orderBy('id')
            ->limit(2)
            ->get();
    }

    private function variantForSkuOnProduct(Product $product, string $sku): ?Variant
    {
        $normalized = strtolower(trim($sku));

        return $product->variants()
            ->with('product')
            ->whereRaw('LOWER(TRIM(sku)) = ?', [$normalized])
            ->orderByRaw('position IS NULL')
            ->orderBy('position')
            ->orderBy('id')
            ->first();
    }

    private function variantForShopifyId(string $shopifyId): ?Variant
    {
        return Variant::query()
            ->with('product')
            ->where('shopify_id', $shopifyId)
            ->first();
    }

    /**
     * @param array<string, string> $normalized
     */
    private function productForRow(array $normalized): ?Product
    {
        $productId = $this->positiveInt($normalized['product_id'] ?? null);
        if ($productId !== null) {
            $product = Product::query()->find($productId);
            if ($product instanceof Product) {
                return $product;
            }
        }

        $shopifyProductId = $this->normalizeShopifyGid($normalized['shopify_product_id'] ?? null, 'Product');
        if ($shopifyProductId !== null) {
            $product = Product::query()
                ->where('shopify_id', $shopifyProductId)
                ->first();
            if ($product instanceof Product) {
                return $product;
            }
        }

        $draftId = $this->positiveInt($normalized['draft_id'] ?? null);
        if ($draftId !== null) {
            $draft = NewProductDraft::query()->find($draftId);
            if ($draft instanceof NewProductDraft) {
                $product = $this->productForDraft($draft);
                if ($product instanceof Product) {
                    return $product;
                }
            }
        }

        $handle = trim((string) ($normalized['handle'] ?? ''));
        if ($handle !== '') {
            return Product::query()
                ->where('handle', $handle)
                ->first();
        }

        return null;
    }

    private function productForDraft(NewProductDraft $draft): ?Product
    {
        $shopifyId = $this->normalizeShopifyGid($draft->shopify_id, 'Product');
        if ($shopifyId !== null) {
            $product = Product::query()
                ->where('shopify_id', $shopifyId)
                ->first();
            if ($product instanceof Product) {
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

    private function singleVariantForProduct(Product $product): ?Variant
    {
        $variants = $product->variants()
            ->with('product')
            ->orderByRaw('position IS NULL')
            ->orderBy('position')
            ->orderBy('id')
            ->limit(2)
            ->get();

        return $variants->count() === 1 ? $variants->first() : null;
    }

    private function saleUpdateSku(Variant $variant, string $fallback): string
    {
        $sku = trim((string) ($variant->sku ?? ''));

        return $sku !== '' ? $sku : $fallback;
    }

    /**
     * @param array<string, string> $normalized
     */
    private function rowIdentifier(array $normalized, ?Product $product = null): string
    {
        foreach (['sku', 'shopify_variant_id', 'shopify_product_id', 'product_id', 'draft_id', 'handle'] as $key) {
            $value = trim((string) ($normalized[$key] ?? ''));
            if ($value !== '') {
                return $value;
            }
        }

        if ($product instanceof Product) {
            return trim((string) ($product->shopify_id ?: $product->handle ?: 'Product #' . $product->id));
        }

        return 'row without SKU or product identifier';
    }

    private function normalizeShopifyGid(mixed $value, string $resource): ?string
    {
        $raw = trim((string) ($value ?? ''));
        if ($raw === '') {
            return null;
        }

        if (str_starts_with($raw, 'gid://shopify/' . $resource . '/')) {
            return $raw;
        }

        if (preg_match('/^\d+$/', $raw) === 1) {
            return 'gid://shopify/' . $resource . '/' . $raw;
        }

        return null;
    }

    private function positiveInt(mixed $value): ?int
    {
        $raw = trim((string) ($value ?? ''));
        if ($raw === '' || preg_match('/^\d+$/', $raw) !== 1) {
            return null;
        }

        $int = (int) $raw;

        return $int > 0 ? $int : null;
    }

    private function preparedSaleTags(string $tags, mixed $type = null): string
    {
        return (string) $this->saleTags->normalizeForStorage($tags, true, $type);
    }

    private function normalizeHeader(string $header): string
    {
        $lower = strtolower(trim($header));
        $lower = preg_replace('/[^\\x20-\\x7E]/', '', $lower);
        $lower = preg_replace('/[^a-z0-9]+/', ' ', $lower);

        return trim((string) $lower);
    }

    private function decimalString(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }

        $raw = trim((string) $value);
        if ($raw === '') {
            return null;
        }

        $normalized = str_replace([' ', ','], ['', '.'], $raw);
        $normalized = preg_replace('/[^0-9.]/', '', $normalized ?? '');
        if ($normalized === null || $normalized === '') {
            return null;
        }

        $parts = explode('.', $normalized);
        if (count($parts) > 2) {
            $normalized = array_shift($parts) . '.' . implode('', $parts);
        }

        return number_format((float) $normalized, 2, '.', '');
    }
}
