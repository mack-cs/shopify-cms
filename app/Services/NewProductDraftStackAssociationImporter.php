<?php

namespace App\Services;

use App\Models\NewProductDraft;
use App\Models\Product;
use App\Models\Variant;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use League\Csv\Reader;

final class NewProductDraftStackAssociationImporter
{
    private const WARNING_LIMIT = 10;

    /**
     * @return array{
     *   total:int,
     *   updated:int,
     *   unchanged:int,
     *   skipped_missing_stack_sku:int,
     *   skipped_stack_not_found:int,
     *   skipped_without_resolved_products:int,
     *   component_skus_resolved:int,
     *   component_skus_not_found:int,
     *   component_skus_ambiguous:int,
     *   warnings:array<int,string>
     * }
     */
    public function importFromPath(string $absolutePath): array
    {
        $csv = $this->readerForPath($absolutePath);

        $result = [
            'total' => 0,
            'updated' => 0,
            'unchanged' => 0,
            'skipped_missing_stack_sku' => 0,
            'skipped_stack_not_found' => 0,
            'skipped_without_resolved_products' => 0,
            'component_skus_resolved' => 0,
            'component_skus_not_found' => 0,
            'component_skus_ambiguous' => 0,
            'warnings' => [],
        ];

        DB::transaction(function () use ($csv, &$result): void {
            foreach ($csv->getRecords() as $row) {
                $result['total']++;
                $rowNumber = $result['total'] + 1;
                $data = $this->normalizeRow($row);

                $stackSku = $this->firstValue($data, ['stack sku', 'bundle sku', 'sku']);
                if ($stackSku === null || $stackSku === '0') {
                    $result['skipped_missing_stack_sku']++;
                    $this->warn($result, "Row {$rowNumber}: missing stack SKU.");
                    continue;
                }

                $draft = $this->resolveDraftByStackSku($stackSku);
                if (!$draft instanceof NewProductDraft) {
                    $result['skipped_stack_not_found']++;
                    $this->warn($result, "Row {$rowNumber}: stack SKU '{$stackSku}' was not found in new product drafts.");
                    continue;
                }

                $componentSkus = $this->componentSkus($data);
                $productIds = [];
                $seen = [];

                foreach ($componentSkus as $componentSku) {
                    $skipReason = 'component_skus_not_found';
                    $productId = $this->resolveComponentProductId($componentSku, $skipReason);

                    if ($productId === null) {
                        $result[$skipReason]++;
                        continue;
                    }

                    if (isset($seen[$productId])) {
                        continue;
                    }

                    $seen[$productId] = true;
                    $productIds[] = $productId;
                    $result['component_skus_resolved']++;
                }

                if ($productIds === []) {
                    $result['skipped_without_resolved_products']++;
                    $this->warn($result, "Row {$rowNumber}: no bracelet SKUs resolved for stack SKU '{$stackSku}'.");
                    continue;
                }

                $current = $this->normalizeProductIds($draft->bundle_product_ids);
                if ($current === $productIds) {
                    $result['unchanged']++;
                    continue;
                }

                NewProductDraft::withoutEvents(function () use ($draft, $productIds): void {
                    $draft->forceFill(['bundle_product_ids' => $productIds])->save();
                });

                $result['updated']++;
            }
        });

        return $result;
    }

    private function readerForPath(string $absolutePath): Reader
    {
        $reader = Reader::createFromPath($absolutePath);
        $reader->setDelimiter($this->detectDelimiter($absolutePath));
        $reader->setHeaderOffset(0);

        return $reader;
    }

    private function detectDelimiter(string $absolutePath): string
    {
        $line = '';
        $handle = fopen($absolutePath, 'r');
        if (is_resource($handle)) {
            $line = (string) fgets($handle);
            fclose($handle);
        }

        $bestDelimiter = ',';
        $bestCount = 0;

        foreach ([',', "\t", ';'] as $delimiter) {
            $count = count(str_getcsv($line, $delimiter));
            if ($count > $bestCount) {
                $bestCount = $count;
                $bestDelimiter = $delimiter;
            }
        }

        return $bestDelimiter;
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
            if ($key === '') {
                continue;
            }

            $normalized[$key] = $value;
        }

        return $normalized;
    }

    private function normalizeHeader(string $header): string
    {
        $header = preg_replace('/^\xEF\xBB\xBF/', '', $header) ?? $header;
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

    /**
     * @param array<string,mixed> $row
     * @return array<int,string>
     */
    private function componentSkus(array $row): array
    {
        $skusByPosition = [];

        foreach ($row as $header => $value) {
            if (preg_match('/^sku\s*(\d+)$/', $header, $matches) !== 1) {
                continue;
            }

            $sku = trim((string) $value);
            if ($sku === '' || $sku === '0') {
                continue;
            }

            $skusByPosition[(int) $matches[1]] = $sku;
        }

        ksort($skusByPosition);

        return array_values(array_unique($skusByPosition));
    }

    private function resolveDraftByStackSku(string $stackSku): ?NewProductDraft
    {
        $draft = NewProductDraft::query()
            ->where('sku', $stackSku)
            ->first();

        if ($draft instanceof NewProductDraft) {
            return $draft;
        }

        $variant = Variant::query()
            ->active()
            ->with('product')
            ->where('sku', $stackSku)
            ->first();

        $product = $variant?->product;
        if (!$product instanceof Product) {
            return null;
        }

        return NewProductDraft::query()
            ->where(function (Builder $query) use ($product): void {
                $shopifyId = trim((string) ($product->shopify_id ?? ''));
                $handle = trim((string) ($product->handle ?? ''));

                if ($shopifyId !== '') {
                    $query->where('shopify_id', $shopifyId);
                }

                if ($handle !== '') {
                    $shopifyId !== ''
                        ? $query->orWhere('handle', $handle)
                        : $query->where('handle', $handle);
                }
            })
            ->first();
    }

    private function resolveComponentProductId(string $sku, string &$skipReason): ?int
    {
        $skipReason = 'component_skus_not_found';

        $productIds = Variant::query()
            ->active()
            ->where('sku', $sku)
            ->whereHas('product', fn (Builder $query): Builder => $query
                ->whereRaw('LOWER(COALESCE(status, "")) != ?', ['archived'])
                ->where(function (Builder $bundleQuery): void {
                    $bundleQuery->where('is_bundle', false)
                        ->orWhereNull('is_bundle');
                }))
            ->pluck('product_id')
            ->map(fn ($id): int => (int) $id)
            ->unique()
            ->values();

        if ($productIds->count() === 1) {
            return (int) $productIds->first();
        }

        $skipReason = $productIds->isEmpty()
            ? 'component_skus_not_found'
            : 'component_skus_ambiguous';

        return null;
    }

    /**
     * @return array<int,int>
     */
    private function normalizeProductIds(mixed $value): array
    {
        if (!is_array($value)) {
            return [];
        }

        $normalized = [];
        $seen = [];

        foreach ($value as $id) {
            $id = (int) $id;
            if ($id <= 0 || isset($seen[$id])) {
                continue;
            }

            $seen[$id] = true;
            $normalized[] = $id;
        }

        return $normalized;
    }

    /**
     * @param array{warnings:array<int,string>} $result
     */
    private function warn(array &$result, string $message): void
    {
        if (count($result['warnings']) >= self::WARNING_LIMIT) {
            return;
        }

        $result['warnings'][] = $message;
    }
}
