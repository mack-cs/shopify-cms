<?php

namespace App\Services\Shopify;

use App\Models\ShopifyOrderItem;
use App\Models\ShopifySyncRun;
use App\Models\SkuDailyDemand;
use Carbon\CarbonInterface;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

final class ShopifyDemandCalculator
{
    /**
     * @param  array<int, string>  $skus
     * @param  array<int, string|\DateTimeInterface>  $dates
     * @return array{sku_dates:int}
     */
    public function recalculate(array $skus, array $dates): array
    {
        $skus = array_values(array_unique(array_filter(array_map([$this, 'normalizeSku'], $skus))));
        $dates = array_values(array_unique(array_filter(array_map([$this, 'normalizeDate'], $dates))));
        $count = 0;

        foreach ($skus as $sku) {
            foreach ($dates as $date) {
                $this->recalculateSkuDate($sku, $date);
                $count++;
            }
        }

        return ['sku_dates' => $count];
    }

    public function recalculateForRun(ShopifySyncRun $run): array
    {
        return $this->recalculate(
            (array) data_get($run->metadata, 'affected_skus', []),
            (array) data_get($run->metadata, 'affected_dates', []),
        );
    }

    private function recalculateSkuDate(string $sku, string $date): void
    {
        $timezone = (string) config('shopify_sync.timezone', 'Africa/Johannesburg');
        $items = ShopifyOrderItem::query()
            ->with(['order', 'refundLineItems'])
            ->where('sku', $sku)
            ->where(function ($query) use ($date, $timezone): void {
                $start = Carbon::parse($date, $timezone)->startOfDay()->utc();
                $end = Carbon::parse($date, $timezone)->addDay()->startOfDay()->utc();

                $query->whereHas('order', function ($orderQuery) use ($start, $end): void {
                    $orderQuery->where(function ($dateQuery) use ($start, $end): void {
                        $dateQuery->where(function ($processedQuery) use ($start, $end): void {
                            $processedQuery->whereNotNull('processed_at_shopify')
                                ->where('processed_at_shopify', '>=', $start)
                                ->where('processed_at_shopify', '<', $end);
                        })->orWhere(function ($createdQuery) use ($start, $end): void {
                            $createdQuery->whereNull('processed_at_shopify')
                                ->where('created_at_shopify', '>=', $start)
                                ->where('created_at_shopify', '<', $end);
                        });
                    });
                });
            })
            ->get();

        $summary = $this->summarize($items);

        SkuDailyDemand::query()->updateOrCreate(
            ['sku' => $sku, 'demand_date' => $date],
            array_merge($summary, ['calculated_at' => now()]),
        );
    }

    /**
     * @param  Collection<int, ShopifyOrderItem>  $items
     * @return array<string, mixed>
     */
    private function summarize(Collection $items): array
    {
        $grossUnits = 0;
        $cancelledUnits = 0;
        $refundedUnits = 0;
        $grossRevenue = 0.0;
        $discountAmount = 0.0;
        $netRevenue = 0.0;
        $orderIds = [];

        foreach ($items as $item) {
            $order = $item->order;
            if (! $order || $order->is_test) {
                continue;
            }

            $includedFinancialStatuses = array_map(
                static fn (mixed $status): string => strtoupper(trim((string) $status)),
                (array) config('shopify_sync.orders.included_financial_statuses', []),
            );
            if ($includedFinancialStatuses !== []
                && ! in_array(strtoupper((string) $order->financial_status), $includedFinancialStatuses, true)) {
                continue;
            }

            $quantity = (int) $item->quantity;
            $isCancelled = $order->cancelled_at_shopify !== null
                || in_array(strtoupper((string) $order->financial_status), ['VOIDED', 'CANCELLED'], true);

            $grossUnits += $quantity;
            $grossRevenue += (float) ($item->original_unit_price ?? 0) * $quantity;
            $discountAmount += (float) ($item->total_discount ?? 0);
            $orderIds[$order->shopify_order_id] = true;

            if ($isCancelled) {
                $cancelledUnits += $quantity;

                continue;
            }

            $itemRefundedUnits = min(
                $quantity,
                (int) $item->refundLineItems->sum('quantity'),
            );
            $itemRefundedAmount = (float) $item->refundLineItems->sum('subtotal_amount');
            $refundedUnits += $itemRefundedUnits;
            $netRevenue += max(0, (float) ($item->discounted_total ?? 0) - $itemRefundedAmount);
        }

        return [
            'gross_units' => $grossUnits,
            'cancelled_units' => $cancelledUnits,
            'refunded_units' => $refundedUnits,
            'net_units' => max(0, $grossUnits - $cancelledUnits - $refundedUnits),
            'order_count' => count($orderIds),
            'gross_revenue' => number_format($grossRevenue, 2, '.', ''),
            'discount_amount' => number_format($discountAmount, 2, '.', ''),
            'net_revenue' => number_format($netRevenue, 2, '.', ''),
        ];
    }

    private function normalizeSku(mixed $sku): ?string
    {
        $sku = strtoupper(trim((string) ($sku ?? '')));

        return $sku === '' ? null : $sku;
    }

    private function normalizeDate(mixed $date): ?string
    {
        if ($date instanceof CarbonInterface) {
            return $date->toDateString();
        }

        $date = trim((string) ($date ?? ''));

        return $date === '' ? null : Carbon::parse($date)->toDateString();
    }
}
