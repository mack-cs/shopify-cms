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
     * @return array{updates:array<int,array{sku:string,variant_id:int,new_price:string}>,skipped_unchanged:int,skipped_unready:int,unready_skus:array<int,string>}
     */
    public function validatedInputs(): array
    {
        $bySku = [];
        $unready = [];
        foreach ($this->sheets->newPriceInputs() as $input) {
            $sku = strtoupper(trim($input['sku']));
            $price = $this->normalizePrice($input['new_price']);
            if ($price === null) {
                $unready[$sku] = $sku;
                unset($bySku[$sku]);
                continue;
            }
            if (isset($bySku[$sku]) && $bySku[$sku] !== $price) {
                $unready[$sku] = $sku;
                unset($bySku[$sku]);
                continue;
            }
            if (! isset($unready[$sku])) {
                $bySku[$sku] = $price;
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
        foreach ($bySku as $sku => $price) {
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
            if (number_format((float) $variant->price, 2, '.', '') === $price) {
                $skipped++;
                continue;
            }

            $updates[] = ['sku' => $sku, 'variant_id' => (int) $variant->id, 'new_price' => $price];
        }

        $unreadySkus = array_values($unready);

        return [
            'updates' => $updates,
            'skipped_unchanged' => $skipped,
            'skipped_unready' => count($unreadySkus),
            'unready_skus' => $unreadySkus,
        ];
    }

    private function normalizePrice(string $value): ?string
    {
        $value = trim($value);
        if (! preg_match('/^\d+(?:\.\d{1,2})?$/', $value) || (float) $value <= 0) {
            return null;
        }

        return number_format((float) $value, 2, '.', '');
    }
}
