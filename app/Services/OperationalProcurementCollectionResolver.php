<?php

namespace App\Services;

use App\Models\ProcurementCollectionConfig;
use App\Models\Product;
use Illuminate\Support\Collection;

final class OperationalProcurementCollectionResolver
{
    /** @var Collection<int,ProcurementCollectionConfig>|null */
    private ?Collection $configured = null;

    public function resolve(Product $product): ProcurementCollectionConfig
    {
        $tokens = TagNormalizer::parseTokens((string) $product->tags);
        $matches = $this->configured()->filter(function (ProcurementCollectionConfig $collection) use ($tokens): bool {
            $handle = TagNormalizer::normalizeToken((string) $collection->collection_handle);

            return $handle !== null
                && (in_array($handle, $tokens, true) || in_array($handle.'-sale', $tokens, true));
        })->values();

        if ($matches->count() === 0) {
            throw new \DomainException("Product [{$product->id}] has no configured operational procurement collection.");
        }
        if ($matches->count() > 1) {
            throw new \DomainException("Product [{$product->id}] has multiple configured operational procurement collections.");
        }

        return $matches->first();
    }

    /** @return Collection<int,ProcurementCollectionConfig> */
    public function configured(): Collection
    {
        return $this->configured ??= ProcurementCollectionConfig::query()
            ->where('is_active', true)
            ->whereRaw("TRIM(COALESCE(google_sheet_tab_name, '')) != ''")
            ->orderBy('id')
            ->get();
    }
}
