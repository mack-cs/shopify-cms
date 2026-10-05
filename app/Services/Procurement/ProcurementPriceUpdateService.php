<?php

namespace App\Services\Procurement;

use App\Jobs\ApplyProcurementPriceUpdatesJob;
use App\Models\Variant;
use App\Services\GoogleSheets\ProcurementSheetSyncService;
use Illuminate\Support\Collection;

final class ProcurementPriceUpdateService
{
    public function __construct(private readonly ProcurementSheetSyncService $sheets) {}

    /** @return array{queued:int,skipped_unchanged:int} */
    public function queueFromSheet(?int $userId = null): array
    {
        $inputs = $this->validatedInputs();
        if ($inputs['updates'] === []) {
            return ['queued' => 0, 'skipped_unchanged' => $inputs['skipped_unchanged']];
        }

        ApplyProcurementPriceUpdatesJob::dispatch($inputs['updates'], $userId)
            ->onQueue((string) config('procurement.queue', 'procurement'));

        return ['queued' => count($inputs['updates']), 'skipped_unchanged' => $inputs['skipped_unchanged']];
    }

    /**
     * @return array{updates:array<int,array{sku:string,variant_id:int,new_price:string}>,skipped_unchanged:int}
     */
    public function validatedInputs(): array
    {
        $bySku = [];
        foreach ($this->sheets->newPriceInputs() as $input) {
            $sku = strtoupper(trim($input['sku']));
            $price = $this->normalizePrice($input['new_price'], $sku);
            if (isset($bySku[$sku]) && $bySku[$sku] !== $price) {
                throw new \RuntimeException("Conflicting New Price values were found for SKU [{$sku}].");
            }
            $bySku[$sku] = $price;
        }

        if ($bySku === []) {
            return ['updates' => [], 'skipped_unchanged' => 0];
        }

        $variants = Variant::query()
            ->active()
            ->whereIn('sku', array_keys($bySku))
            ->whereHas('product', fn ($query) => $query->procurementCatalogEligible())
            ->with('product')
            ->get()
            ->groupBy(fn (Variant $variant): string => strtoupper(trim((string) $variant->sku)));

        $updates = [];
        $skipped = 0;
        foreach ($bySku as $sku => $price) {
            /** @var Collection<int,Variant> $matches */
            $matches = $variants->get($sku, collect());
            if ($matches->count() !== 1) {
                throw new \RuntimeException("SKU [{$sku}] matched {$matches->count()} active variants; price updates require exactly one match.");
            }

            $variant = $matches->first();
            if (trim((string) $variant->shopify_id) === '') {
                throw new \RuntimeException("SKU [{$sku}] has no Shopify variant ID.");
            }
            if (trim((string) $variant->product?->shopify_id) === '') {
                throw new \RuntimeException("SKU [{$sku}] has no Shopify product ID.");
            }
            if (number_format((float) $variant->price, 2, '.', '') === $price) {
                $skipped++;
                continue;
            }

            $updates[] = ['sku' => $sku, 'variant_id' => (int) $variant->id, 'new_price' => $price];
        }

        return ['updates' => $updates, 'skipped_unchanged' => $skipped];
    }

    private function normalizePrice(string $value, string $sku): string
    {
        $value = trim($value);
        if (! preg_match('/^\d+(?:\.\d{1,2})?$/', $value)) {
            throw new \RuntimeException("New Price for SKU [{$sku}] must be numeric with up to 2 decimal places.");
        }
        if ((float) $value <= 0) {
            throw new \RuntimeException("New Price for SKU [{$sku}] must be greater than zero.");
        }

        return number_format((float) $value, 2, '.', '');
    }
}
