<?php

namespace App\Services\Procurement;

use App\Models\ProcurementPrediction;
use App\Models\ProcurementSupplierOrderLine;
use App\Models\Variant;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

final class ProcurementRecommendationCalculator
{
    /**
     * @param  Collection<int, ProcurementSupplierOrderLine>|null  $lines
     * @return array<string, mixed>
     */
    public function calculate(Variant $variant, ProcurementPrediction $prediction, ?Collection $lines = null): array
    {
        $lines ??= $this->openLines($variant->id);
        $lines = $lines->filter(fn (ProcurementSupplierOrderLine $line): bool => $line->quantity_outstanding > 0)
            ->sortBy(fn (ProcurementSupplierOrderLine $line): string => ($line->eta_date?->format('Y-m-d') ?? '9999-12-31').'|'.str_pad((string) $line->id, 12, '0', STR_PAD_LEFT))
            ->values();

        $available = $variant->inventory_tracked === true
            ? (int) ($variant->current_inventory_quantity ?? $variant->inventory_qty ?? 0)
            : 0;
        $onHand = $variant->inventory_tracked === true
            ? (int) ($variant->current_on_hand_quantity ?? $available)
            : 0;
        $snapshotInventory = $prediction->current_inventory;
        $storedGross = max(0, (int) ($prediction->recommended_order_before_incoming_stock
            ?? ((int) ($prediction->additional_order_required ?? $prediction->preliminary_order_quantity ?? 0)
                + $lines->sum(fn (ProcurementSupplierOrderLine $line): int => $line->quantity_outstanding))));
        $targetStock = ($snapshotInventory === null ? $available : (int) $snapshotInventory) + $storedGross;
        $gross = max(0, $targetStock - $available);
        $weeklyDemand = $prediction->predicted_weekly_demand;
        $dailyDemand = $weeklyDemand === null ? null : max(0, (float) $weeklyDemand) / 7;
        $runout = $this->adjustedRunoutDate($prediction, $available, $dailyDemand);
        $graceDays = (int) config('procurement.stock_gap_grace_days', 0);
        $today = now((string) config('procurement.timezone', 'Africa/Johannesburg'))->startOfDay();

        $first = $lines->get(0);
        $second = $lines->get(1);
        $firstIsOverdue = $first?->eta_date?->lt($today) ?? false;
        $stockGapStatus = $first === null
            ? 'NO_PENDING_ORDER'
            : ($first->eta_date === null
                ? 'INSUFFICIENT_DATA'
                : ($runout === null && $onHand > 0
                ? ($firstIsOverdue ? 'UNHEALTHY' : null)
                : ($firstIsOverdue || $onHand <= 0 || $runout?->copy()->addDays($graceDays)->lt($first->eta_date)
                    ? 'UNHEALTHY'
                    : 'HEALTHY')));

        $timelyOutstanding = 0;
        $runoutAfterFirst = null;
        $projectedBeforeSecond = null;
        $betweenOrdersStatus = $second === null ? 'NO_SECOND_ORDER' : 'INSUFFICIENT_DATA';

        if ($first?->eta_date !== null) {
            // When demand/runout data is unavailable, preserve the quantity-based
            // behaviour instead of claiming that a known PO is late.
            if ($stockGapStatus !== 'UNHEALTHY') {
                $timelyOutstanding += $first->quantity_outstanding;
            }

            if ($dailyDemand !== null) {
                $daysUntilFirst = max(0, (int) $today->diffInDays($first->eta_date, false));
                $stockAtFirst = max(0, $onHand - ($dailyDemand * $daysUntilFirst));
                $projected = $stockAtFirst + $first->quantity_outstanding;
                if ($dailyDemand > 0) {
                    $runoutAfterFirst = $first->eta_date->copy()->addDays((int) floor($projected / $dailyDemand));
                }

                $previousEta = $first->eta_date;
                foreach ($lines->skip(1) as $index => $line) {
                    if ($line->eta_date === null) {
                        if ($index === 1) {
                            $betweenOrdersStatus = 'INSUFFICIENT_DATA';
                        }
                        break;
                    }
                    $daysBetween = max(0, (int) $previousEta->diffInDays($line->eta_date, false));
                    $projected -= $dailyDemand * $daysBetween;
                    $healthy = $dailyDemand <= 0 || $projected + ($dailyDemand * $graceDays) >= 0;

                    if ($index === 1) {
                        $projectedBeforeSecond = round($projected, 2);
                        $betweenOrdersStatus = $healthy ? 'HEALTHY' : 'UNHEALTHY';
                    }
                    if ($stockGapStatus === 'UNHEALTHY' || ! $healthy) {
                        break;
                    }

                    $timelyOutstanding += $line->quantity_outstanding;
                    $projected += $line->quantity_outstanding;
                    $previousEta = $line->eta_date;
                }
            } elseif ($stockGapStatus !== 'UNHEALTHY') {
                $timelyOutstanding = $lines->sum(fn (ProcurementSupplierOrderLine $line): int => $line->quantity_outstanding);
            }
        }

        $totalOutstanding = $lines->sum(fn (ProcurementSupplierOrderLine $line): int => $line->quantity_outstanding);

        return [
            'available' => $available,
            'on_hand' => $onHand,
            'gross_requirement' => $gross,
            'total_outstanding' => $totalOutstanding,
            'timely_outstanding' => $timelyOutstanding,
            'additional_order_required' => max(0, $gross - $timelyOutstanding),
            'predicted_runout_date' => $runout,
            'stock_gap_status' => $stockGapStatus,
            'predicted_runout_date_after_replenishment' => $runoutAfterFirst,
            'projected_stock_before_second_eta' => $projectedBeforeSecond,
            'between_orders_stock_gap_status' => $betweenOrdersStatus,
            'next_order' => $first,
            'second_order' => $second,
            'next_order_is_overdue' => $firstIsOverdue,
        ];
    }

    /** @return Collection<int, ProcurementSupplierOrderLine> */
    private function openLines(int $variantId): Collection
    {
        return ProcurementSupplierOrderLine::query()
            ->where('variant_id', $variantId)
            ->where('status', 'open')
            ->with('order')
            ->withSum(['receipts as received_quantity' => fn ($query) => $query->where('status', 'succeeded')], 'quantity_received')
            ->get();
    }

    private function adjustedRunoutDate(ProcurementPrediction $prediction, int $available, ?float $dailyDemand): ?Carbon
    {
        $runout = $prediction->predicted_runout_date?->copy()->startOfDay();
        $snapshot = $prediction->current_inventory;
        if ($runout === null || $snapshot === null || $dailyDemand === null || $dailyDemand <= 0) {
            return $runout;
        }

        return $runout->addDays((int) floor(($available - (int) $snapshot) / $dailyDemand));
    }
}
