<?php

namespace App\Services\Procurement;

use App\Models\ChangeLog;
use App\Models\ProcurementIncomingStock;
use App\Models\ProcurementPrediction;
use App\Models\ProcurementPredictionRun;
use App\Models\ProcurementSupplierOrderLine;
use App\Models\Variant;
use Illuminate\Support\Facades\DB;

final class SupplierOrderSummaryService
{
    /** @return array{total_quantity_on_order:int,number_of_wip_orders:int} */
    public function forVariant(int $variantId): array
    {
        $lines = ProcurementSupplierOrderLine::query()
            ->where('variant_id', $variantId)
            ->where('status', 'open')
            ->withSum(['receipts as received_quantity' => fn ($query) => $query->where('status', 'succeeded')], 'quantity_received')
            ->get()
            ->filter(fn (ProcurementSupplierOrderLine $line): bool => $line->quantity_outstanding > 0);

        return [
            'total_quantity_on_order' => $lines->sum(fn (ProcurementSupplierOrderLine $line): int => $line->quantity_outstanding),
            'number_of_wip_orders' => $lines->pluck('supplier_order_id')->unique()->count(),
        ];
    }

    public function refreshVariant(Variant $variant, ?int $changedBy = null, string $source = 'cms:supplier-orders'): ProcurementIncomingStock
    {
        $stock = DB::transaction(function () use ($variant, $changedBy, $source): ProcurementIncomingStock {
            $summary = $this->forVariant($variant->id);
            $stock = ProcurementIncomingStock::query()->where('variant_id', $variant->id)->lockForUpdate()->first();
            $stock ??= new ProcurementIncomingStock([
                'variant_id' => $variant->id,
                'sku' => strtoupper(trim((string) $variant->sku)),
                'quantity_on_order_phase_1' => 0,
                'quantity_on_order_phase_2' => 0,
                'quantity_on_order_phase_3' => 0,
            ]);
            $before = [
                'total_quantity_on_order' => (int) ($stock->total_quantity_on_order ?? 0),
                'number_of_wip_orders' => (int) ($stock->number_of_wip_orders ?? 0),
            ];
            $changed = ! $stock->exists || $before !== $summary;
            $stock->forceFill([
                ...$summary,
                'total_confirmed_quantity_on_order' => $summary['total_quantity_on_order'],
                'source_sheet' => $source,
                'detected_at' => now(),
                'input_changed_at' => $changed ? now() : $stock->input_changed_at,
            ])->save();

            if ($changed) {
                foreach ($summary as $field => $value) {
                    if ($before[$field] === $value) {
                        continue;
                    }
                    ChangeLog::query()->create([
                        'import_id' => $variant->product?->import_id,
                        'product_id' => $variant->product_id,
                        'changed_by' => $changedBy,
                        'source' => $source,
                        'model_type' => ProcurementIncomingStock::class,
                        'model_id' => $stock->id,
                        'field' => $field,
                        'old_value' => (string) $before[$field],
                        'new_value' => (string) $value,
                    ]);
                }
            }

            return $stock;
        });

        $this->refreshLatestRecommendation($variant, $stock->total_quantity_on_order);

        return $stock;
    }

    private function refreshLatestRecommendation(Variant $variant, int $outstanding): void
    {
        $runId = ProcurementPredictionRun::query()
            ->where('status', ProcurementPredictionRun::STATUS_COMPLETED)
            ->latest('calculation_date')->latest('id')->value('id');
        if (! $runId || blank($variant->shopify_id)) {
            return;
        }
        $prediction = ProcurementPrediction::query()
            ->where('procurement_prediction_run_id', $runId)
            ->where('shopify_variant_id', $variant->shopify_id)
            ->first();
        if (! $prediction) {
            return;
        }
        $currentInventory = $variant->inventory_tracked === true
            ? (int) ($variant->current_inventory_quantity ?? $variant->inventory_qty ?? 0)
            : 0;
        $requiredBeforeIncoming = max(0, (int) ($prediction->recommended_order_before_incoming_stock ?? 0));
        $additional = max(0, $requiredBeforeIncoming - $outstanding);
        $prediction->forceFill([
            'total_quantity_on_order' => $outstanding,
            'total_confirmed_quantity_on_order' => $outstanding,
            'procurement_actioned' => $outstanding > 0,
            'projected_inventory_position' => $currentInventory + $outstanding,
            'additional_order_required' => $additional,
            'preliminary_order_quantity' => $additional,
            'incoming_stock_covers_requirement' => $outstanding > 0 && $additional === 0,
        ])->save();
    }
}
