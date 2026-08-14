<?php

namespace App\Services\GoogleSheets;

use App\Models\ProcurementPrediction;
use App\Models\ProcurementPredictionRun;
use App\Models\ProductMovementReportRow;
use App\Models\ProductMovementReportRun;
use App\Models\Variant;
use App\Services\OperationalProcurementCollectionResolver;
use App\Services\SalePercentageCalculator;
use Illuminate\Support\Facades\Log;

final class ProcurementSheetDatasetBuilder
{
    public function __construct(
        private readonly OperationalProcurementCollectionResolver $collections,
        private readonly SalePercentageCalculator $salePercentages,
    ) {}

    /** @return array<int,array<string,mixed>> */
    public function records(): array
    {
        $predictionRun = ProcurementPredictionRun::query()
            ->where('status', ProcurementPredictionRun::STATUS_COMPLETED)
            ->latest('calculation_date')->latest('id')->first();
        $predictions = $predictionRun
            ? ProcurementPrediction::query()->where('procurement_prediction_run_id', $predictionRun->id)
                ->get()->keyBy(fn (ProcurementPrediction $row): string => trim((string) $row->shopify_variant_id))
            : collect();
        $movementRun = ProductMovementReportRun::query()
            ->where('status', ProductMovementReportRun::STATUS_COMPLETED)
            ->latest('calculation_date')->latest('id')->first();
        $movement = $movementRun
            ? ProductMovementReportRow::query()->where('product_movement_report_run_id', $movementRun->id)
                ->get()->keyBy(fn (ProductMovementReportRow $row): string => trim((string) $row->shopify_variant_id))
            : collect();

        $records = [];
        Variant::query()->active()->whereNotNull('sku')
            ->whereRaw("TRIM(COALESCE(sku, '')) != ''")
            ->whereHas('product', fn ($query) => $query->activeStatus())
            ->with(['product', 'procurementIncomingStock'])
            ->orderBy('id')->chunkById(500, function ($variants) use (&$records, $predictions, $movement, $predictionRun): void {
                foreach ($variants as $variant) {
                    $sku = strtoupper(trim((string) $variant->sku));
                    $prediction = $predictions->get(trim((string) $variant->shopify_id));
                    $movementRow = $movement->get(trim((string) $variant->shopify_id));
                    $stock = $variant->procurementIncomingStock;
                    $predictionStale = (bool) $stock?->isStaleFor($predictionRun);
                    $phase1 = (int) ($stock?->quantity_on_order_phase_1 ?? 0);
                    $phase2 = (int) ($stock?->quantity_on_order_phase_2 ?? 0);
                    $phase3 = (int) ($stock?->quantity_on_order_phase_3 ?? 0);
                    $total = $phase1 + $phase2 + $phase3;
                    $confirmedTotal = (int) ($stock?->total_confirmed_quantity_on_order ?? 0);
                    $current = $variant->inventory_tracked === true
                        ? ($variant->current_inventory_quantity ?? $variant->inventory_qty)
                        : null;
                    $collectionId = null;
                    try {
                        $collectionId = $this->collections->resolve($variant->product)->id;
                    } catch (\DomainException $exception) {
                        Log::warning('Procurement collection resolution failed', [
                            'sku' => $sku, 'product_id' => $variant->product_id,
                            'error' => $exception->getMessage(),
                        ]);
                    }

                    $records[] = [
                        '_collection_id' => $collectionId,
                        '_prediction_stale' => $predictionStale,
                        '_procurement_actioned' => $confirmedTotal > 0,
                        'sku' => $sku,
                        'product' => $variant->product?->title,
                        'vendor' => $variant->product?->vendor,
                        'product_type' => $variant->product?->type,
                        'currently_on_sale' => $variant->compare_at_price !== null
                            && (float) $variant->compare_at_price > (float) $variant->price,
                        'sale_percentage' => $this->salePercentages->percentage(
                            $variant->price, $variant->compare_at_price
                        ),
                        'current_inventory' => $current,
                        'action_required' => $prediction?->action_status,
                        'ignore' => (bool) ($stock?->ignore ?? false),
                        'quantity_on_order_phase_1' => $phase1,
                        'order_id_phase_1' => $stock?->order_id_phase_1,
                        'eta_date_phase_1' => $stock?->eta_date_phase_1?->toDateString(),
                        'quantity_on_order_phase_2' => $phase2,
                        'order_id_phase_2' => $stock?->order_id_phase_2,
                        'eta_date_phase_2' => $stock?->eta_date_phase_2?->toDateString(),
                        'quantity_on_order_phase_3' => $phase3,
                        'order_id_phase_3' => $stock?->order_id_phase_3,
                        'eta_date_phase_3' => $stock?->eta_date_phase_3?->toDateString(),
                        'total_quantity_on_order' => $total,
                        // This operational column must move with live inventory even
                        // between prediction runs; the ML value is a point-in-time snapshot.
                        'projected_inventory_position' => ($current ?? 0) + $confirmedTotal,
                        'predicted_weekly_demand' => $prediction?->predicted_weekly_demand,
                        'estimated_days_of_stock_remaining' => $prediction?->estimated_days_of_stock_remaining,
                        'predicted_runout_date' => $prediction?->predicted_runout_date?->toDateString(),
                        'lead_time_days' => $prediction?->lead_time_days_used,
                        'stock_required_for_lead_time' => $prediction?->stock_required_for_lead_time,
                        'recommended_order_before_incoming_stock' => $prediction?->recommended_order_before_incoming_stock,
                        'additional_order_required' => $prediction?->additional_order_required
                            ?? $prediction?->preliminary_order_quantity,
                        'cms_movement_classification' => $prediction?->cms_movement_classification
                            ?? $movementRow?->movement_classification,
                        'action_reason' => $prediction?->action_reason,
                        'stockout_before_incoming_arrival' => (bool) ($prediction?->stockout_before_incoming_arrival ?? false),
                        'incoming_stock_covers_requirement' => (bool) ($prediction?->incoming_stock_covers_requirement ?? false),
                        // This is deliberately the Shopify inventory refresh time,
                        // not the sheet publish time, so stale stock remains visible.
                        'last_updated' => $variant->inventory_last_synced_at
                            ?->timezone((string) config('procurement.timezone', 'Africa/Johannesburg'))
                            ->format('Y-m-d H:i:s'),
                    ];
                }
            });

        $priority = [
            'ORDER_NOW' => 0, 'ATTENTION_WITHIN_3_WEEKS' => 1, 'MANUAL_REVIEW' => 2,
            'INSUFFICIENT_DATA' => 3, 'MONITOR' => 4, 'NO_ACTION' => 5,
        ];
        usort($records, static function (array $left, array $right) use ($priority): int {
            return [
                $priority[$left['action_required'] ?? ''] ?? 99,
                (int) ($left['_procurement_actioned'] ?? false),
                $left['sku'],
            ] <=> [
                $priority[$right['action_required'] ?? ''] ?? 99,
                (int) ($right['_procurement_actioned'] ?? false),
                $right['sku'],
            ];
        });

        return $records;
    }
}
