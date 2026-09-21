<?php

namespace App\Services;

use App\Models\Product;
use App\Models\Variant;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

final class DuplicateSkuAuditService
{
    /**
     * @return array<int, int>
     */
    public function conflictingProductIds(?string $status = null): array
    {
        $normalizedStatus = strtolower(trim((string) $status));

        return collect($this->findConflicts())
            ->flatMap(fn (array $conflict): array => $conflict['products'])
            ->filter(function (array $product) use ($normalizedStatus): bool {
                if ($normalizedStatus === '') {
                    return true;
                }

                return strtolower(trim((string) $product['status'])) === $normalizedStatus;
            })
            ->pluck('id')
            ->map(fn ($id): int => (int) $id)
            ->unique()
            ->sort()
            ->values()
            ->all();
    }

    /**
     * Find SKUs used by variants belonging to more than one distinct product.
     *
     * SKU matching is case-insensitive and ignores surrounding whitespace.
     *
     * @return array<int, array{
     *     sku:string,
     *     product_count:int,
     *     variant_count:int,
     *     products:array<int, array{
     *         id:int,
     *         title:string,
     *         handle:string,
     *         status:string,
     *         variant_ids:array<int, int>,
     *         recorded_skus:array<int, string>
     *     }>
     * }>
     */
    public function findConflicts(): array
    {
        $normalizedSkus = Variant::query()
            ->active()
            ->whereNotNull('sku')
            ->whereRaw("TRIM(sku) <> ''")
            ->selectRaw('UPPER(TRIM(sku)) as normalized_sku')
            ->groupBy(DB::raw('UPPER(TRIM(sku))'))
            ->havingRaw('COUNT(DISTINCT product_id) > 1')
            ->orderBy('normalized_sku')
            ->pluck('normalized_sku');

        if ($normalizedSkus->isEmpty()) {
            return [];
        }

        $variants = $normalizedSkus
            ->chunk(500)
            ->flatMap(fn (Collection $chunk): Collection => Variant::query()
                ->active()
                ->with(['product:id,title,handle,status'])
                ->whereIn(DB::raw('UPPER(TRIM(sku))'), $chunk->all())
                ->orderBy('product_id')
                ->orderBy('id')
                ->get(['id', 'product_id', 'sku']))
            ->filter(fn (Variant $variant): bool => $variant->product instanceof Product)
            ->groupBy(fn (Variant $variant): string => strtoupper(trim((string) $variant->sku)));

        return $normalizedSkus
            ->map(function (string $normalizedSku) use ($variants): ?array {
                /** @var Collection<int, Variant> $skuVariants */
                $skuVariants = $variants->get($normalizedSku, collect());

                $products = $skuVariants
                    ->groupBy('product_id')
                    ->map(function (Collection $productVariants): array {
                        /** @var Variant $firstVariant */
                        $firstVariant = $productVariants->first();
                        /** @var Product $product */
                        $product = $firstVariant->product;

                        return [
                            'id' => (int) $product->id,
                            'title' => trim((string) $product->title) ?: "Product #{$product->id}",
                            'handle' => trim((string) $product->handle),
                            'status' => trim((string) $product->status) ?: 'unknown',
                            'variant_ids' => $productVariants->pluck('id')->map(fn ($id): int => (int) $id)->values()->all(),
                            'recorded_skus' => $productVariants
                                ->pluck('sku')
                                ->map(fn ($sku): string => trim((string) $sku))
                                ->unique()
                                ->values()
                                ->all(),
                        ];
                    })
                    ->values();

                if ($products->count() < 2) {
                    return null;
                }

                return [
                    'sku' => $normalizedSku,
                    'product_count' => $products->count(),
                    'variant_count' => $skuVariants->count(),
                    'products' => $products->all(),
                ];
            })
            ->filter()
            ->values()
            ->all();
    }
}
