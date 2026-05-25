<?php

namespace App\Services;

use App\Models\Product;
use Illuminate\Support\Collection;

final class ComplementaryDependencyService
{
    public function __construct(
        private readonly ComplementaryProductAuditService $auditService,
    ) {
    }

    /**
     * @return Collection<int, Product>
     */
    public function productsReferencingProduct(Product $child): Collection
    {
        $childId = (int) $child->getKey();
        if ($childId <= 0) {
            return collect();
        }

        $parents = collect();

        Product::query()
            ->select(['id', 'import_id', 'handle', 'shopify_id', 'status'])
            ->whereNotNull('handle')
            ->where('handle', '!=', '')
            ->chunkById(200, function (Collection $products) use (&$parents, $childId): void {
                foreach ($products as $product) {
                    if (!$product instanceof Product) {
                        continue;
                    }

                    $value = $this->auditService->localComplementaryValueForProduct($product);
                    $tokens = $this->auditService->parseReferenceTokens($value);
                    $productIds = $this->auditService->resolveProductIdsFromTokens($tokens);

                    if (in_array($childId, $productIds, true)) {
                        $parents->push($product);
                    }
                }
            });

        return $parents
            ->unique(fn (Product $product): int => (int) $product->getKey())
            ->values();
    }
}
