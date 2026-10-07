<?php

namespace App\Services\Procurement;

use App\Jobs\ApplyProcurementPriceUpdatesJob;
use App\Models\Variant;
use App\Services\GoogleSheets\ProcurementSheetSyncService;
use Illuminate\Support\Collection;

final class ProcurementPriceUpdateService
{
    public function __construct(private readonly ProcurementSheetSyncService $sheets) {}

    /**
     * @return array{queued:int,skipped_unchanged:int,skipped_unready:int,unready_skus:array<int,string>}
     */
    public function queueFromSheet(?int $userId = null): array
    {
        $inputs = $this->validatedInputs();
        $summary = [
            'queued' => count($inputs['updates']),
            'skipped_unchanged' => $inputs['skipped_unchanged'],
            'skipped_unready' => $inputs['skipped_unready'],
            'unready_skus' => $inputs['unready_skus'],
        ];
        if ($inputs['updates'] === []) {
            return $summary;
        }

        ApplyProcurementPriceUpdatesJob::dispatch($inputs['updates'], $userId)
            ->onQueue((string) config('procurement.queue', 'procurement'));

        return $summary;
    }

    /**
     * @return array{updates:array<int,array{sku:string,variant_id:int,new_price?:string,new_cost?:string}>,skipped_unchanged:int,skipped_unready:int,unready_skus:array<int,string>}
     */
    public function validatedInputs(): array
    {
        $bySku = [];
        $unready = [];
        foreach ($this->sheets->newPriceInputs() as $input) {
            $sku = strtoupper(trim($input['sku']));
            $price = trim((string) ($input['new_price'] ?? '')) === ''
                ? null
                : $this->normalizeMoney((string) $input['new_price']);
            $cost = trim((string) ($input['new_cost'] ?? '')) === ''
                ? null
                : $this->normalizeMoney((string) $input['new_cost']);
            if (($input['new_price'] ?? '') !== '' && $price === null) {
                $unready[$sku] = $sku;
                unset($bySku[$sku]);
                continue;
            }
            if (($input['new_cost'] ?? '') !== '' && $cost === null) {
                $unready[$sku] = $sku;
                unset($bySku[$sku]);
                continue;
            }
            if ($price === null && $cost === null) {
                $unready[$sku] = $sku;
                unset($bySku[$sku]);
                continue;
            }
            $candidate = array_filter([
                'new_price' => $price,
                'new_cost' => $cost,
            ], fn (?string $value): bool => $value !== null);
            if (isset($bySku[$sku]) && $bySku[$sku] !== $candidate) {
                $unready[$sku] = $sku;
                unset($bySku[$sku]);
                continue;
            }
            if (! isset($unready[$sku])) {
                $bySku[$sku] = $candidate;
            }
        }

        if ($bySku === []) {
            $skus = array_values($unready);

            return ['updates' => [], 'skipped_unchanged' => 0, 'skipped_unready' => count($skus), 'unready_skus' => $skus];
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
        foreach ($bySku as $sku => $requested) {
            /** @var Collection<int,Variant> $matches */
            $matches = $variants->get($sku, collect());
            if ($matches->count() !== 1) {
                $unready[$sku] = $sku;
                continue;
            }

            $variant = $matches->first();
            if (trim((string) $variant->shopify_id) === '' || trim((string) $variant->product?->shopify_id) === '') {
                $unready[$sku] = $sku;
                continue;
            }

            $update = ['sku' => $sku, 'variant_id' => (int) $variant->id];
            if (isset($requested['new_price']) && number_format((float) $variant->price, 2, '.', '') !== $requested['new_price']) {
                $update['new_price'] = $requested['new_price'];
            }
            if (isset($requested['new_cost']) && $this->currentCost($variant) !== $requested['new_cost']) {
                $update['new_cost'] = $requested['new_cost'];
            }
            if (count($update) === 2) {
                $skipped++;
                continue;
            }

            $updates[] = $update;
        }

        $unreadySkus = array_values($unready);

        return [
            'updates' => $updates,
            'skipped_unchanged' => $skipped,
            'skipped_unready' => count($unreadySkus),
            'unready_skus' => $unreadySkus,
        ];
    }

    private function normalizeMoney(string $value): ?string
    {
        $value = trim($value);
        if (! preg_match('/^\d+(?:\.\d{1,2})?$/', $value) || (float) $value <= 0) {
            return null;
        }

        return number_format((float) $value, 2, '.', '');
    }

    private function currentCost(Variant $variant): ?string
    {
        $variant->loadMissing('product');
        $product = $variant->product;
        if (! $product) {
            return null;
        }

        $row = \App\Models\ShopifyRow::query()
            ->where('import_id', $product->import_id)
            ->where('handle', $product->handle)
            ->where('row_type', 'variant')
            ->latest('id')
            ->get()
            ->first(fn (\App\Models\ShopifyRow $row): bool => strtoupper(trim((string) $row->get(\App\Services\HeaderStore::VARIANT_SKU, ''))) === strtoupper(trim((string) $variant->sku)));
        $cost = trim((string) ($row?->get(\App\Services\HeaderStore::COST_PER_ITEM, '') ?? ''));
        if ($cost === '') {
            $cost = trim((string) (\App\Models\ShopifyRow::query()
                ->where('import_id', $product->import_id)
                ->where('handle', $product->handle)
                ->where('row_type', 'product_primary')
                ->latest('id')
                ->first()?->get(\App\Services\HeaderStore::COST_PER_ITEM, '') ?? ''));
        }

        return is_numeric($cost) ? number_format((float) $cost, 2, '.', '') : null;
    }
}
