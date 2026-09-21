<?php

namespace App\Services\GoogleSheets;

use App\Models\ProcurementPrediction;
use App\Models\ProcurementPredictionRun;
use App\Models\ProcurementSupplierOrderLine;
use App\Models\ProductMovementReportRow;
use App\Models\ProductMovementReportRun;
use App\Models\Variant;
use App\Services\OperationalProcurementCollectionResolver;
use App\Services\Procurement\ProcurementActionPolicy;
use App\Services\Procurement\ProcurementRecommendationCalculator;
use App\Services\SalePercentageCalculator;
use Illuminate\Support\Facades\Log;

final class ProcurementSheetDatasetBuilder
{
    private readonly ProcurementRecommendationCalculator $recommendations;

    public function __construct(
        private readonly OperationalProcurementCollectionResolver $collections,
        private readonly SalePercentageCalculator $salePercentages,
        private readonly ProcurementActionPolicy $actionPolicy,
        ?ProcurementRecommendationCalculator $recommendations = null,
    ) {
        $this->recommendations = $recommendations ?? app(ProcurementRecommendationCalculator::class);
    }

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
        $pendingOrders = ProcurementSupplierOrderLine::query()
            ->where('status', 'open')
            ->with('order')
            ->withSum(['receipts as received_quantity' => fn ($query) => $query->where('status', 'succeeded')], 'quantity_received')
            ->orderBy('eta_date')->orderBy('id')->get()
            ->filter(fn (ProcurementSupplierOrderLine $line): bool => $line->quantity_outstanding > 0)
            ->groupBy('variant_id');
        $duplicateSkus = Variant::query()->active()->whereNotNull('sku')
            ->whereRaw("TRIM(COALESCE(sku, '')) != ''")
            ->whereHas('product', fn ($query) => $query->activeStatus()->nonBundle())
            ->selectRaw('UPPER(TRIM(sku)) AS normalized_sku')
            ->groupByRaw('UPPER(TRIM(sku))')
            ->havingRaw('COUNT(*) > 1')
            ->pluck('normalized_sku')
            ->flip();

        $records = [];
        Variant::query()->active()->whereNotNull('sku')
            ->whereRaw("TRIM(COALESCE(sku, '')) != ''")
            ->whereHas('product', fn ($query) => $query->activeStatus()->nonBundle())
            ->with(['product', 'procurementIncomingStock'])
            ->orderBy('id')->chunkById(500, function ($variants) use (&$records, $predictions, $movement, $predictionRun, $pendingOrders, $duplicateSkus): void {
                foreach ($variants as $variant) {
                    $sku = strtoupper(trim((string) $variant->sku));
                    $prediction = $predictions->get(trim((string) $variant->shopify_id));
                    $movementRow = $movement->get(trim((string) $variant->shopify_id));
                    $stock = $variant->procurementIncomingStock;
                    $predictionStale = (bool) $stock?->isStaleFor($predictionRun);
                    $outstandingTotal = (int) ($stock?->total_quantity_on_order ?? 0);
                    $current = $variant->inventory_tracked === true
                        ? ($variant->current_inventory_quantity ?? $variant->inventory_qty)
                        : null;
                    $currentOnHand = $variant->inventory_tracked === true
                        ? ($variant->current_on_hand_quantity ?? $current)
                        : null;
                    $nextOrders = $pendingOrders->get($variant->id, collect())->take(2)->values();
                    $calculation = $prediction === null ? null : $this->recommendations->calculate(
                        $variant,
                        $prediction,
                        $pendingOrders->get($variant->id, collect()),
                    );
                    $nextOrder = $calculation['next_order'] ?? $nextOrders->get(0);
                    $secondOrder = $calculation['second_order'] ?? $nextOrders->get(1);
                    $runout = $calculation['predicted_runout_date'] ?? null;
                    $replenishment = $nextOrder?->eta_date;
                    $additional = (int) ($calculation['additional_order_required']
                        ?? $prediction?->additional_order_required ?? $prediction?->preliminary_order_quantity ?? 0);
                    $stockGapStatus = $calculation['stock_gap_status']
                        ?? ($nextOrder === null ? 'NO_PENDING_ORDER' : null);
                    $runoutAfterReplenishment = $calculation['predicted_runout_date_after_replenishment'] ?? null;
                    $projectedBeforeSecondEta = $calculation['projected_stock_before_second_eta'] ?? null;
                    $betweenOrdersGapStatus = $calculation['between_orders_stock_gap_status'] ?? 'NO_SECOND_ORDER';
                    $ignored = (bool) ($stock?->ignore ?? false);
                    $action = $prediction === null
                        ? ($ignored ? 'NO_ACTION' : 'INSUFFICIENT_DATA')
                        : $this->actionPolicy->resolve(
                            $prediction->action_status,
                            $additional,
                            $runout,
                            (int) ($prediction->attention_horizon_days ?? config('procurement.attention_horizon_days', 21)),
                            $ignored,
                            availableInventory: $current,
                            unhealthyCurrentStockGap: $stockGapStatus === 'UNHEALTHY',
                            unhealthyBetweenOrdersGap: $betweenOrdersGapStatus === 'UNHEALTHY',
                        );
                    $actionReason = $this->actionReason(
                        $prediction,
                        $predictionRun !== null,
                        $ignored,
                        $stockGapStatus,
                        $betweenOrdersGapStatus,
                        (bool) ($calculation['next_order_is_overdue'] ?? false),
                        $additional,
                        (int) ($calculation['timely_outstanding'] ?? 0),
                        $movementRow?->data_quality_note,
                        $duplicateSkus->has($sku),
                    );
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
                        '_variant_id' => $variant->id,
                        '_collection_id' => $collectionId,
                        '_prediction_stale' => $predictionStale,
                        '_procurement_actioned' => $outstandingTotal > 0,
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
                        'action_required' => $action,
                        'ignore' => $ignored,
                        'quantity_to_order' => (int) ($stock?->quantity_to_order ?? 0),
                        'total_quantity_on_order' => $outstandingTotal,
                        'number_of_wip_orders' => (int) ($stock?->number_of_wip_orders ?? 0),
                        'next_order_id' => $nextOrder?->order?->order_number,
                        'next_eta' => $nextOrder?->eta_date?->format('d/m/Y'),
                        'second_order_id' => $secondOrder?->order?->order_number,
                        'second_eta' => $secondOrder?->eta_date?->format('d/m/Y'),
                        'predicted_runout_date_after_replenishment' => $runoutAfterReplenishment?->format('d/m/Y'),
                        'projected_stock_before_second_eta' => $projectedBeforeSecondEta,
                        'between_orders_stock_gap_status' => $betweenOrdersGapStatus,
                        // This operational column must move with live inventory even
                        // between prediction runs; the ML value is a point-in-time snapshot.
                        'projected_inventory_position' => ($current ?? 0) + $outstandingTotal,
                        'predicted_weekly_demand' => $prediction?->predicted_weekly_demand,
                        'estimated_days_of_stock_remaining' => $prediction?->estimated_days_of_stock_remaining,
                        'predicted_runout_date' => $currentOnHand !== null && $currentOnHand <= 0
                            ? 'OUT_OF_STOCK'
                            : $runout?->format('d/m/Y'),
                        'replenishment_date' => $replenishment?->format('d/m/Y'),
                        'stock_gap_status' => $stockGapStatus,
                        'lead_time_days' => $prediction?->lead_time_days_used,
                        'stock_required_for_lead_time' => $prediction?->stock_required_for_lead_time,
                        'recommended_order_before_incoming_stock' => $calculation['gross_requirement']
                            ?? $prediction?->recommended_order_before_incoming_stock,
                        'additional_order_required' => $prediction === null ? null : $additional,
                        'cms_movement_classification' => $prediction?->cms_movement_classification
                            ?? $movementRow?->movement_classification,
                        'action_reason' => $actionReason,
                        'stockout_before_incoming_arrival' => $stockGapStatus === 'UNHEALTHY',
                        'incoming_stock_covers_requirement' => ($calculation['timely_outstanding'] ?? 0) > 0 && $additional === 0,
                        'current_committed_inventory' => $variant->inventory_tracked === true
                            ? $variant->current_committed_quantity
                            : null,
                        'current_reserved_inventory' => $variant->inventory_tracked === true
                            ? $variant->current_reserved_quantity
                            : null,
                        'current_on_hand_inventory' => $variant->inventory_tracked === true
                            ? $variant->current_on_hand_quantity
                            : null,
                        // This is deliberately the Shopify inventory refresh time,
                        // not the sheet publish time, so stale stock remains visible.
                        'last_updated' => $variant->inventory_last_synced_at
                            ?->timezone((string) config('procurement.timezone', 'Africa/Johannesburg'))
                            ->format('d/m/Y H:i'),
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

    private function actionReason(
        ?ProcurementPrediction $prediction,
        bool $hasCompletedRun,
        bool $ignored,
        ?string $stockGapStatus,
        string $betweenOrdersGapStatus,
        bool $nextOrderIsOverdue,
        int $additional,
        int $timelyOutstanding,
        ?string $movementDataNote,
        bool $duplicateSku,
    ): string {
        if ($prediction === null) {
            if ($ignored) {
                return 'SKU is marked Ignore / end of life; sell through remaining inventory and do not replenish.';
            }

            $reason = $duplicateSku
                ? 'The SKU is duplicated across multiple active product variants, so it was excluded from the procurement forecast.'
                : ($hasCompletedRun
                ? 'The latest procurement run did not produce a forecast for this SKU; review its demand history or exclusion status.'
                : 'No completed procurement calculation is available for this SKU.');
            $movementDataNote = trim((string) $movementDataNote);

            return $movementDataNote === '' ? $reason : $reason.' '.$movementDataNote;
        }

        if ($nextOrderIsOverdue) {
            return 'The earliest open incoming order is overdue and has not been received; it is not counted as timely stock.';
        }
        if ($stockGapStatus === 'UNHEALTHY') {
            return 'Incoming stock does not arrive in time to prevent the predicted stock gap.';
        }
        if ($betweenOrdersGapStatus === 'UNHEALTHY') {
            return 'The first replenishment is projected to run out before the second order arrives.';
        }
        if ($additional === 0 && $timelyOutstanding > 0) {
            return 'Existing incoming stock arriving in time covers the forecast requirement.';
        }

        $reason = trim((string) $prediction->action_reason);
        if (str_contains(strtolower($reason), 'arrival timing is not tracked')) {
            return 'Outstanding incoming stock has been assessed using its ETA; additional stock is still required.';
        }

        return $reason !== ''
            ? $reason
            : (trim((string) $prediction->data_quality_warning)
                ?: 'The procurement calculation did not provide an explanation; review the forecast data.');
    }
}
