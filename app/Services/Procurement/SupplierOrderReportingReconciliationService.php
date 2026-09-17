<?php

namespace App\Services\Procurement;

use App\Models\ProcurementSupplierOrderLine;
use App\Models\Variant;
use Illuminate\Support\Facades\DB;

final class SupplierOrderReportingReconciliationService
{
    public function __construct(private readonly SupplierOrderSummaryService $summary) {}

    /**
     * @return array{lines_checked:int,lines_updated:int,variants_refreshed:int}
     */
    public function reconcile(?int $changedBy = null, bool $dryRun = false): array
    {
        $linesChecked = 0;
        $linesUpdated = 0;
        $variantIds = [];

        ProcurementSupplierOrderLine::query()
            ->with('variant')
            ->withSum(['receipts as succeeded_quantity' => fn ($query) => $query->where('status', 'succeeded')], 'quantity_received')
            ->orderBy('id')
            ->chunkById(200, function ($lines) use (&$linesChecked, &$linesUpdated, &$variantIds, $dryRun): void {
                foreach ($lines as $line) {
                    $linesChecked++;
                    $received = (int) ($line->succeeded_quantity ?? 0);
                    $targetStatus = $received >= (int) $line->quantity_ordered ? 'completed' : 'open';
                    $updates = [];

                    if ($line->status !== $targetStatus) {
                        $updates['status'] = $targetStatus;
                        $updates['completed_at'] = $targetStatus === 'completed'
                            ? ($line->completed_at ?? now())
                            : null;
                    }

                    if ($updates !== []) {
                        $linesUpdated++;
                        if (! $dryRun) {
                            ProcurementSupplierOrderLine::query()->whereKey($line->id)->update($updates);
                        }
                    }

                    if ($line->variant_id !== null) {
                        $variantIds[] = (int) $line->variant_id;
                    }
                }
            });

        $variantIds = array_values(array_unique($variantIds));
        if (! $dryRun && $variantIds !== []) {
            Variant::query()
                ->whereIn('id', $variantIds)
                ->with(['product', 'procurementIncomingStock'])
                ->orderBy('id')
                ->chunkById(100, function ($variants) use ($changedBy): void {
                    DB::transaction(function () use ($variants, $changedBy): void {
                        foreach ($variants as $variant) {
                            $this->summary->refreshVariant($variant, $changedBy, 'cms:reporting-reconcile');
                        }
                    });
                });
        }

        return [
            'lines_checked' => $linesChecked,
            'lines_updated' => $linesUpdated,
            'variants_refreshed' => count($variantIds),
        ];
    }
}
