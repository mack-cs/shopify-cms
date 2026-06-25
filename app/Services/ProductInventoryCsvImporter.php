<?php

namespace App\Services;

use App\Models\Product;
use App\Models\ProductInventorySnapshot;
use App\Models\Variant;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use League\Csv\Reader;

final class ProductInventoryCsvImporter
{
    private const WARNING_LIMIT = 8;

    public function __construct(
        private readonly ProductInventoryHistoryRecorder $historyRecorder,
    ) {
    }

    /**
     * @return array{
     *   total:int,
     *   updated:int,
     *   unchanged:int,
     *   snapshots:int,
     *   skipped_missing_identifier:int,
     *   skipped_missing_quantity:int,
     *   skipped_invalid_quantity:int,
     *   skipped_invalid_tracked:int,
     *   skipped_not_found:int,
     *   skipped_ambiguous:int,
     *   warnings:array<int,string>
     * }
     */
    public function importFromPath(string $absolutePath, ?int $userId = null): array
    {
        $result = [
            'total' => 0,
            'updated' => 0,
            'unchanged' => 0,
            'snapshots' => 0,
            'skipped_missing_identifier' => 0,
            'skipped_missing_quantity' => 0,
            'skipped_invalid_quantity' => 0,
            'skipped_invalid_tracked' => 0,
            'skipped_not_found' => 0,
            'skipped_ambiguous' => 0,
            'warnings' => [],
        ];

        $csv = Reader::createFromPath($absolutePath);
        $csv->setHeaderOffset(0);

        $observedProductIds = [];

        DB::transaction(function () use ($csv, $userId, &$result, &$observedProductIds): void {
            foreach ($csv->getRecords() as $row) {
                $result['total']++;
                $rowNumber = $result['total'] + 1;
                $data = $this->normalizeRow($row);

                $quantityValue = $this->firstValue($data, [
                    'inventory_qty',
                    'inventory quantity',
                    'inventory qty',
                    'variant inventory qty',
                    'variant inventory quantity',
                    'inventory',
                    'stock',
                    'stock quantity',
                    'quantity',
                    'qty',
                    'available',
                    'available quantity',
                    'inventory available',
                    'inventory available in stock',
                    'on hand',
                    'stock on hand',
                ]);

                $trackedValue = $this->firstValue($data, [
                    'inventory_tracked',
                    'inventory tracked',
                    'variant inventory tracked',
                    'tracked',
                ]);
                $tracked = $this->parseTracked($trackedValue);

                if ($trackedValue !== null && $tracked === null) {
                    $result['skipped_invalid_tracked']++;
                    $this->warn($result, "Row {$rowNumber}: invalid tracked value '{$trackedValue}'.");
                    continue;
                }

                if ($quantityValue === null && $tracked !== false) {
                    $result['skipped_missing_quantity']++;
                    $this->warn($result, "Row {$rowNumber}: missing stock quantity.");
                    continue;
                }

                $quantity = null;
                if ($quantityValue !== null) {
                    $quantity = $this->parseQuantity($quantityValue);
                    if ($quantity === null) {
                        $result['skipped_invalid_quantity']++;
                        $this->warn($result, "Row {$rowNumber}: invalid stock quantity '{$quantityValue}'.");
                        continue;
                    }
                }

                $skipReason = 'skipped_missing_identifier';
                $variant = $this->resolveVariant($data, $skipReason);
                if (!$variant instanceof Variant) {
                    $result[$skipReason]++;
                    $this->warn($result, $this->skipMessage($rowNumber, $skipReason, $data));
                    continue;
                }

                $newTracked = $tracked ?? true;
                $newQuantity = $newTracked === false ? null : $quantity;
                $currentQuantity = $variant->inventory_qty === null ? null : (int) $variant->inventory_qty;

                $changed = $variant->inventory_tracked !== $newTracked
                    || $currentQuantity !== $newQuantity
                    || filled($variant->inventory_sync_error);

                InventoryOperationContext::run(function () use ($variant, $newTracked, $newQuantity): void {
                    $variant->inventory_tracked = $newTracked;
                    $variant->inventory_qty = $newTracked === false ? null : $newQuantity;
                    $variant->inventory_sync_error = null;
                    $variant->save();
                });

                $observedProductIds[(int) $variant->product_id] = true;
                $result[$changed ? 'updated' : 'unchanged']++;
            }

            foreach (array_keys($observedProductIds) as $productId) {
                $product = Product::query()->with('variants')->find((int) $productId);
                if (!$product instanceof Product) {
                    continue;
                }

                $this->historyRecorder->record(
                    $product,
                    $userId,
                    ProductInventorySnapshot::SOURCE_STOCK_IMPORT,
                );
                $result['snapshots']++;
            }
        });

        return $result;
    }

    /**
     * @param array<string,mixed> $row
     */
    private function resolveVariant(array $row, string &$skipReason): ?Variant
    {
        $skipReason = 'skipped_missing_identifier';

        $variantId = $this->firstValue($row, ['variant_id', 'variant id', 'local variant id']);
        if ($variantId !== null) {
            $variant = Variant::query()
                ->active()
                ->with('product')
                ->whereKey((int) $variantId)
                ->whereHas('product', fn (Builder $query): Builder => $this->nonArchivedProductQuery($query))
                ->first();

            if ($variant instanceof Variant) {
                return $variant;
            }

            $skipReason = 'skipped_not_found';
            return null;
        }

        $shopifyVariantId = $this->firstValue($row, [
            'shopify variant id',
            'variant shopify id',
            'shopify_variant_id',
            'variant gid',
            'shopify variant gid',
        ]);
        if ($shopifyVariantId !== null) {
            $variant = $this->variantByShopifyId($shopifyVariantId);
            if ($variant instanceof Variant) {
                return $variant;
            }

            $skipReason = 'skipped_not_found';
            return null;
        }

        $sku = $this->firstValue($row, ['sku', 'variant sku', 'variant_sku']);
        $product = $this->resolveProduct($row);
        if ($product instanceof Product) {
            return $this->variantForProduct($product, $sku, $skipReason);
        }

        if ($sku !== null) {
            return $this->variantBySku($sku, $skipReason);
        }

        return null;
    }

    /**
     * @param array<string,mixed> $row
     */
    private function resolveProduct(array $row): ?Product
    {
        $productId = $this->firstValue($row, ['product_id', 'product id', 'local product id']);
        if ($productId !== null) {
            $product = $this->productByLocalOrShopifyId($productId);
            if ($product instanceof Product) {
                return $product;
            }
        }

        $shopifyProductId = $this->firstValue($row, [
            'shopify product id',
            'product shopify id',
            'shopify_product_id',
            'shopify id',
            'product gid',
            'shopify product gid',
        ]);
        if ($shopifyProductId !== null) {
            $product = $this->productByShopifyId($shopifyProductId);
            if ($product instanceof Product) {
                return $product;
            }
        }

        $handle = $this->firstValue($row, ['handle', 'product handle']);
        if ($handle !== null) {
            return Product::query()
                ->where('handle', $handle)
                ->where(fn (Builder $query): Builder => $this->nonArchivedProductQuery($query))
                ->first();
        }

        return null;
    }

    private function variantForProduct(Product $product, ?string $sku, string &$skipReason): ?Variant
    {
        $query = $product->variants()->orderBy('id');

        if ($sku !== null) {
            $variants = $query->where('sku', $sku)->limit(2)->get();
            if ($variants->count() === 1) {
                return $variants->first();
            }

            $skipReason = $variants->isEmpty() ? 'skipped_not_found' : 'skipped_ambiguous';
            return null;
        }

        $variants = $query->limit(2)->get();
        if ($variants->count() === 1) {
            return $variants->first();
        }

        $skipReason = $variants->isEmpty() ? 'skipped_not_found' : 'skipped_ambiguous';
        return null;
    }

    private function variantBySku(string $sku, string &$skipReason): ?Variant
    {
        $variants = Variant::query()
            ->active()
            ->where('sku', $sku)
            ->whereHas('product', fn (Builder $query): Builder => $this->nonArchivedProductQuery($query))
            ->orderBy('id')
            ->limit(2)
            ->get();

        if ($variants->count() === 1) {
            return $variants->first();
        }

        $skipReason = $variants->isEmpty() ? 'skipped_not_found' : 'skipped_ambiguous';
        return null;
    }

    private function variantByShopifyId(string $value): ?Variant
    {
        $candidates = $this->shopifyIdCandidates($value, 'ProductVariant');

        return Variant::query()
            ->active()
            ->whereIn('shopify_id', $candidates)
            ->whereHas('product', fn (Builder $query): Builder => $this->nonArchivedProductQuery($query))
            ->first();
    }

    private function productByLocalOrShopifyId(string $value): ?Product
    {
        if (str_contains($value, 'gid://shopify/Product/')) {
            return $this->productByShopifyId($value);
        }

        if (ctype_digit($value)) {
            $product = Product::query()
                ->whereKey((int) $value)
                ->where(fn (Builder $query): Builder => $this->nonArchivedProductQuery($query))
                ->first();

            if ($product instanceof Product) {
                return $product;
            }
        }

        return $this->productByShopifyId($value);
    }

    private function productByShopifyId(string $value): ?Product
    {
        $candidates = $this->shopifyIdCandidates($value, 'Product');

        return Product::query()
            ->whereIn('shopify_id', $candidates)
            ->where(fn (Builder $query): Builder => $this->nonArchivedProductQuery($query))
            ->first();
    }

    private function nonArchivedProductQuery(Builder $query): Builder
    {
        return $query->whereRaw('LOWER(COALESCE(status, "")) != ?', ['archived']);
    }

    /**
     * @return array<int,string>
     */
    private function shopifyIdCandidates(string $value, string $resource): array
    {
        $value = trim($value);
        $candidates = [$value];
        $numericId = $this->shopifyNumericId($value);

        if ($numericId !== null) {
            $candidates[] = $numericId;
            $candidates[] = "gid://shopify/{$resource}/{$numericId}";
        }

        return array_values(array_unique(array_filter($candidates, fn (string $candidate): bool => $candidate !== '')));
    }

    private function shopifyNumericId(string $value): ?string
    {
        if (preg_match('/(\d+)\s*$/', trim($value), $matches) !== 1) {
            return null;
        }

        return $matches[1];
    }

    /**
     * @param array<string,mixed> $row
     * @return array<string,mixed>
     */
    private function normalizeRow(array $row): array
    {
        $normalized = [];

        foreach ($row as $header => $value) {
            $key = $this->normalizeHeader((string) $header);
            if ($key === '' || array_key_exists($key, $normalized)) {
                continue;
            }

            $normalized[$key] = $value;
        }

        return $normalized;
    }

    private function normalizeHeader(string $header): string
    {
        $header = strtolower(trim($header));
        $header = preg_replace('/[^a-z0-9]+/', ' ', $header) ?? $header;

        return trim(preg_replace('/\s+/', ' ', $header) ?? $header);
    }

    /**
     * @param array<string,mixed> $row
     * @param array<int,string> $headers
     */
    private function firstValue(array $row, array $headers): ?string
    {
        foreach ($headers as $header) {
            $key = $this->normalizeHeader($header);
            if (!array_key_exists($key, $row)) {
                continue;
            }

            $value = trim((string) $row[$key]);
            if ($value !== '') {
                return $value;
            }
        }

        return null;
    }

    private function parseQuantity(string $value): ?int
    {
        $normalized = str_replace([',', ' '], '', trim($value));

        if (preg_match('/^[+-]?\d+(?:\.0+)?$/', $normalized) !== 1) {
            return null;
        }

        return (int) $normalized;
    }

    private function parseTracked(?string $value): ?bool
    {
        if ($value === null) {
            return null;
        }

        $value = strtolower(trim($value));

        return match ($value) {
            '1', 'true', 'yes', 'y', 'tracked' => true,
            '0', 'false', 'no', 'n', 'not tracked', 'untracked' => false,
            default => null,
        };
    }

    /**
     * @param array{
     *   warnings:array<int,string>
     * } $result
     */
    private function warn(array &$result, string $message): void
    {
        if (count($result['warnings']) >= self::WARNING_LIMIT) {
            return;
        }

        $result['warnings'][] = $message;
    }

    /**
     * @param array<string,mixed> $row
     */
    private function skipMessage(int $rowNumber, string $skipReason, array $row): string
    {
        $identifier = $this->rowIdentifierLabel($row);

        return match ($skipReason) {
            'skipped_missing_identifier' => "Row {$rowNumber}: missing product ID, Shopify product ID, handle, SKU, or variant ID.",
            'skipped_ambiguous' => "Row {$rowNumber}: {$identifier} matched more than one active variant; add SKU or variant ID.",
            'skipped_not_found' => "Row {$rowNumber}: {$identifier} was not found.",
            default => "Row {$rowNumber}: skipped.",
        };
    }

    /**
     * @param array<string,mixed> $row
     */
    private function rowIdentifierLabel(array $row): string
    {
        foreach ([
            'variant id',
            'shopify variant id',
            'product id',
            'shopify product id',
            'handle',
            'sku',
        ] as $header) {
            $value = $this->firstValue($row, [$header]);
            if ($value !== null) {
                return "{$header} '{$value}'";
            }
        }

        return 'row';
    }
}
