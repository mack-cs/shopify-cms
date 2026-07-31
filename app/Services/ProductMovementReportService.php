<?php

namespace App\Services;

use App\Models\Product;
use App\Models\ProductInventorySnapshot;
use App\Models\ProductMovementReportRow;
use App\Models\ProductMovementReportRun;
use App\Models\ShopifyInventorySnapshot;
use App\Models\ShopifyOrderItem;
use App\Models\Variant;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

final class ProductMovementReportService
{
    public function createRun(string $from, string $to, ?int $userId = null): ProductMovementReportRun
    {
        $start = Carbon::parse($from)->startOfDay();
        $end = Carbon::parse($to)->startOfDay();
        if ($end->lt($start)) {
            throw new \InvalidArgumentException('The report end date must be on or after the start date.');
        }

        return ProductMovementReportRun::query()->create([
            'requested_by' => $userId,
            'analysis_start_date' => $start->toDateString(),
            'analysis_end_date' => $end->toDateString(),
            'months_analysed' => $this->monthsAnalysed($start, $end),
            'status' => ProductMovementReportRun::STATUS_QUEUED,
            'settings' => config('product_movement'),
        ]);
    }

    public function generate(ProductMovementReportRun $run): ProductMovementReportRun
    {
        $start = $run->analysis_start_date->copy()->startOfDay();
        $end = $run->analysis_end_date->copy()->startOfDay();
        $timezone = (string) config('product_movement.timezone', 'Africa/Johannesburg');
        $utcStart = $start->copy()->timezone($timezone)->startOfDay()->utc();
        $utcEnd = $end->copy()->timezone($timezone)->addDay()->startOfDay()->utc();

        $run->forceFill([
            'status' => ProductMovementReportRun::STATUS_RUNNING,
            'started_at' => now(),
            'completed_at' => null,
            'failure_message' => null,
        ])->save();
        $run->rows()->delete();

        $identity = $this->variantIdentity();
        $sales = $this->salesMetrics($identity, $utcStart, $utcEnd, $timezone);
        $snapshots = $this->snapshotMetrics($identity, $end);
        $rows = [];
        $rowCount = 0;

        Variant::query()
            ->active()
            ->with('product')
            ->orderBy('id')
            ->chunkById(500, function (Collection $variants) use (
                $run,
                $start,
                $end,
                $sales,
                $snapshots,
                &$rows,
                &$rowCount,
            ): void {
                foreach ($variants as $variant) {
                    if (!$variant instanceof Variant || !$variant->product instanceof Product) {
                        continue;
                    }

                    $rows[] = $this->row(
                        $run,
                        $variant,
                        $variant->product,
                        $start,
                        $end,
                        $sales[(int) $variant->id] ?? $this->emptySales(),
                        $snapshots[(int) $variant->id] ?? [],
                    );
                    $rowCount++;

                    if (count($rows) >= 500) {
                        ProductMovementReportRow::query()->insert($rows);
                        $rows = [];
                    }
                }
            });

        Product::query()
            ->doesntHave('variants')
            ->orderBy('id')
            ->chunkById(500, function (Collection $products) use ($run, $start, $end, &$rows, &$rowCount): void {
                foreach ($products as $product) {
                    if (!$product instanceof Product) {
                        continue;
                    }

                    $rows[] = $this->productWithoutVariantRow($run, $product, $start, $end);
                    $rowCount++;

                    if (count($rows) >= 500) {
                        ProductMovementReportRow::query()->insert($rows);
                        $rows = [];
                    }
                }
            });

        if ($rows !== []) {
            ProductMovementReportRow::query()->insert($rows);
        }

        $run->forceFill([
            'status' => ProductMovementReportRun::STATUS_COMPLETED,
            'row_count' => $rowCount,
            'completed_at' => now(),
        ])->save();

        return $run->fresh() ?? $run;
    }

    /**
     * @return array{
     *   shopify:array<string,int>,
     *   sku:array<string,int|null>,
     *   product:array<int,array<int,int>>
     * }
     */
    private function variantIdentity(): array
    {
        $shopify = [];
        $skuGroups = [];
        $products = [];

        Variant::query()
            ->active()
            ->orderBy('id')
            ->select(['id', 'product_id', 'shopify_id', 'sku'])
            ->chunkById(1000, function (Collection $variants) use (&$shopify, &$skuGroups, &$products): void {
                foreach ($variants as $variant) {
                    $variantId = (int) $variant->id;
                    $shopifyId = trim((string) $variant->shopify_id);
                    $sku = $this->normalizeSku($variant->sku);

                    if ($shopifyId !== '') {
                        $shopify[$shopifyId] = $variantId;
                    }
                    if ($sku !== '') {
                        $skuGroups[$sku][] = $variantId;
                    }
                    $products[(int) $variant->product_id][] = $variantId;
                }
            });

        $sku = [];
        foreach ($skuGroups as $key => $ids) {
            $sku[$key] = count(array_unique($ids)) === 1 ? (int) $ids[0] : null;
        }

        return [
            'shopify' => $shopify,
            'sku' => $sku,
            'product' => $products,
        ];
    }

    /**
     * @param array<string,mixed> $identity
     * @return array<int,array<string,mixed>>
     */
    private function salesMetrics(array $identity, Carbon $utcStart, Carbon $utcEnd, string $timezone): array
    {
        $metrics = [];
        $includedStatuses = array_map(
            static fn (mixed $status): string => strtoupper(trim((string) $status)),
            (array) config('shopify_sync.orders.included_financial_statuses', []),
        );

        ShopifyOrderItem::query()
            ->with('order')
            ->whereHas('order', fn ($query) => $query
                ->where(function ($dateQuery) use ($utcStart, $utcEnd): void {
                    $dateQuery
                        ->where(function ($processed) use ($utcStart, $utcEnd): void {
                            $processed->whereNotNull('processed_at_shopify')
                                ->where('processed_at_shopify', '>=', $utcStart)
                                ->where('processed_at_shopify', '<', $utcEnd);
                        })
                        ->orWhere(function ($created) use ($utcStart, $utcEnd): void {
                            $created->whereNull('processed_at_shopify')
                                ->where('created_at_shopify', '>=', $utcStart)
                                ->where('created_at_shopify', '<', $utcEnd);
                        });
                }))
            ->orderBy('id')
            ->chunkById(1000, function (Collection $items) use (&$metrics, $identity, $includedStatuses, $timezone): void {
                foreach ($items as $item) {
                    $order = $item->order;
                    if (
                        !$order
                        || $order->is_test
                        || $order->cancelled_at_shopify !== null
                        || in_array(strtoupper((string) $order->financial_status), ['VOIDED', 'CANCELLED'], true)
                    ) {
                        continue;
                    }
                    if (
                        $includedStatuses !== []
                        && !in_array(strtoupper((string) $order->financial_status), $includedStatuses, true)
                    ) {
                        continue;
                    }

                    $variantId = $this->resolveVariantId(
                        $identity,
                        (string) $item->shopify_variant_id,
                        (string) ($item->sku ?: $item->variant_sku),
                    );
                    if ($variantId === null) {
                        continue;
                    }

                    $dateValue = $order->processed_at_shopify ?? $order->created_at_shopify;
                    if ($dateValue === null) {
                        continue;
                    }
                    $date = $dateValue->copy()->timezone($timezone)->toDateString();
                    $quantity = max(0, (int) $item->quantity);
                    $currentQuantity = $item->current_quantity === null
                        ? $quantity
                        : max(0, min($quantity, (int) $item->current_quantity));
                    $refunded = max(0, $quantity - $currentQuantity);

                    $metrics[$variantId] ??= $this->emptySales();
                    $metrics[$variantId]['gross'] += $quantity;
                    $metrics[$variantId]['refunded'] += $refunded;
                    $metrics[$variantId]['net'] += max(0, $quantity - $refunded);
                    $metrics[$variantId]['orders'][(string) $order->shopify_order_id] = true;
                    $metrics[$variantId]['months'][substr($date, 0, 7)] = true;
                    $metrics[$variantId]['first'] = $metrics[$variantId]['first'] === null
                        ? $date
                        : min($metrics[$variantId]['first'], $date);
                    $metrics[$variantId]['last'] = $metrics[$variantId]['last'] === null
                        ? $date
                        : max($metrics[$variantId]['last'], $date);
                    $metrics[$variantId]['daily_net'][$date] =
                        ($metrics[$variantId]['daily_net'][$date] ?? 0) + max(0, $quantity - $refunded);
                }
            });

        return $metrics;
    }

    /**
     * @param array<string,mixed> $identity
     * @return array<int,array<string,mixed>>
     */
    private function snapshotMetrics(array $identity, Carbon $end): array
    {
        $metrics = [];

        ShopifyInventorySnapshot::query()
            ->whereNotNull('business_date')
            ->whereDate('business_date', '<=', $end->toDateString())
            ->orderBy('id')
            ->chunkById(1000, function (Collection $snapshots) use (&$metrics, $identity): void {
                foreach ($snapshots as $snapshot) {
                    $variantId = $this->resolveVariantId(
                        $identity,
                        (string) $snapshot->shopify_variant_id,
                        (string) $snapshot->sku,
                    );
                    if ($variantId === null || $snapshot->business_date === null) {
                        continue;
                    }

                    $date = $snapshot->business_date->toDateString();
                    $metrics[$variantId]['source'] = 'shopify_inventory_snapshots';
                    $metrics[$variantId]['daily'][$date] =
                        ($metrics[$variantId]['daily'][$date] ?? 0) + (int) ($snapshot->available ?? 0);
                }
            });

        ProductInventorySnapshot::query()
            ->whereDate('checked_date', '<=', $end->toDateString())
            ->whereNotNull('variant_summary')
            ->orderBy('id')
            ->chunkById(500, function (Collection $snapshots) use (&$metrics, $identity): void {
                foreach ($snapshots as $snapshot) {
                    $date = $snapshot->checked_date?->toDateString();
                    if ($date === null) {
                        continue;
                    }

                    foreach ((array) $snapshot->variant_summary as $variantSummary) {
                        if (!is_array($variantSummary)) {
                            continue;
                        }

                        $localId = is_numeric($variantSummary['id'] ?? null)
                            ? (int) $variantSummary['id']
                            : null;
                        $variantId = $localId
                            ?? $this->resolveVariantId(
                                $identity,
                                (string) ($variantSummary['shopify_id'] ?? ''),
                                (string) ($variantSummary['sku'] ?? ''),
                            );
                        if ($variantId === null || isset($metrics[$variantId]['daily'][$date])) {
                            continue;
                        }
                        if (($variantSummary['quantity'] ?? null) === null) {
                            continue;
                        }

                        $metrics[$variantId]['source'] ??= 'product_inventory_snapshots.variant_summary';
                        $metrics[$variantId]['daily'][$date] = (int) $variantSummary['quantity'];
                    }
                }
            });

        foreach ($metrics as &$metric) {
            ksort($metric['daily']);
        }

        return $metrics;
    }

    /**
     * @param array<string,mixed> $sales
     * @param array<string,mixed> $snapshots
     * @return array<string,mixed>
     */
    private function row(
        ProductMovementReportRun $run,
        Variant $variant,
        Product $product,
        Carbon $start,
        Carbon $end,
        array $sales,
        array $snapshots,
    ): array {
        $periodDays = $start->diffInDays($end) + 1;
        $months = max(0.01, (float) $run->months_analysed);
        $calendarMonths = $this->calendarMonths($start, $end);
        $gross = (int) $sales['gross'];
        $refunded = (int) $sales['refunded'];
        $net = (int) $sales['net'];
        $monthsWithSales = count($sales['months']);
        $consistency = $calendarMonths > 0 ? ($monthsWithSales / $calendarMonths) * 100 : 0;
        $lastSale = $sales['last'] ? Carbon::parse($sales['last']) : null;
        $daysSinceLastSale = $lastSale ? $lastSale->diffInDays($end, false) : null;
        $snapshot = $this->summarizeSnapshots($snapshots, $sales, $start, $end);
        $currentInventory = $variant->inventory_tracked === true
            ? ($variant->current_available_quantity ?? $variant->current_inventory_quantity ?? $variant->inventory_qty)
            : null;
        $price = $variant->price === null ? null : (float) $variant->price;
        $compareAt = $variant->compare_at_price === null ? null : (float) $variant->compare_at_price;
        $onSale = $price !== null && $compareAt !== null && $compareAt > $price;
        $discount = $onSale && $compareAt > 0 ? (($compareAt - $price) / $compareAt) * 100 : null;
        $averageMonthly = $net / $months;
        $average30Days = $periodDays > 0 ? ($net / $periodDays) * 30 : 0;
        $score = $this->movementScore(
            $averageMonthly,
            $net,
            $consistency,
            $daysSinceLastSale,
            count($sales['orders']),
            $currentInventory,
            $snapshot['units_per_30_in_stock_days'],
        );
        $createdAt = $product->shopify_created_at ?? $product->created_at;
        $classification = $this->classification(
            $score,
            $averageMonthly,
            $net,
            $consistency,
            $daysSinceLastSale,
            $createdAt,
            $end,
            $snapshot,
        );
        $recommendation = $this->managerRecommendation(
            $classification,
            $net,
            $averageMonthly,
            $monthsWithSales,
            $months,
            $currentInventory,
            $onSale,
            $daysSinceLastSale,
            $createdAt,
            $end,
            $snapshot,
        );
        $notes = $this->qualityNotes($run, $variant, $product, $sales, $snapshot, $createdAt);

        return [
            'product_movement_report_run_id' => $run->id,
            'product_id' => $product->id,
            'variant_id' => $variant->id,
            'shopify_product_id' => $product->shopify_id,
            'shopify_variant_id' => $variant->shopify_id,
            'product_title' => $product->title,
            'variant_title' => $this->variantTitle($variant),
            'sku' => trim((string) $variant->sku),
            'vendor' => $product->vendor,
            'product_type' => $product->type,
            'product_status' => strtolower(trim((string) $product->status)) ?: 'unknown',
            'variant_status' => $this->variantStatus($variant, $product),
            'product_created_at' => $createdAt,
            'analysis_start_date' => $start->toDateString(),
            'analysis_end_date' => $end->toDateString(),
            'months_analysed' => round($months, 2),
            'gross_units_sold' => $gross,
            'refunded_units' => $refunded,
            'net_units_sold' => $net,
            'order_count' => count($sales['orders']),
            'average_units_per_month' => round($averageMonthly, 4),
            'average_units_per_30_days' => round($average30Days, 4),
            'months_with_sales' => $monthsWithSales,
            'sales_consistency_percentage' => round($consistency, 2),
            'first_sale_date' => $sales['first'],
            'last_sale_date' => $sales['last'],
            'days_since_last_sale' => $daysSinceLastSale === null ? null : max(0, $daysSinceLastSale),
            'first_inventory_snapshot_date' => $snapshot['first_date'],
            'snapshot_days_available' => $snapshot['days'],
            'in_stock_days' => $snapshot['in_stock_days'],
            'out_of_stock_days' => $snapshot['out_of_stock_days'],
            'units_sold_per_30_in_stock_days' => $snapshot['units_per_30_in_stock_days'],
            'opening_snapshot_inventory' => $snapshot['opening'],
            'average_snapshot_inventory' => $snapshot['average'],
            'closing_snapshot_inventory' => $snapshot['closing'],
            'current_inventory' => $currentInventory,
            'inventory_tracked' => $variant->inventory_tracked,
            'current_inventory_status' => $this->inventoryStatus($variant, $currentInventory),
            'movement_score' => round($score, 2),
            'movement_classification' => $classification,
            'recommended_action' => $recommendation['action'],
            'manager_reason' => $recommendation['reason'],
            'currently_on_sale' => $onSale,
            'current_price' => $price,
            'compare_at_price' => $compareAt,
            'discount_percentage' => $discount === null ? null : round($discount, 2),
            'has_snapshot_history' => $snapshot['days'] !== null,
            'data_quality_note' => implode(' ', $notes),
            'created_at' => now(),
            'updated_at' => now(),
        ];
    }

    /**
     * @return array<string,mixed>
     */
    private function productWithoutVariantRow(
        ProductMovementReportRun $run,
        Product $product,
        Carbon $start,
        Carbon $end,
    ): array {
        return [
            'product_movement_report_run_id' => $run->id,
            'product_id' => $product->id,
            'variant_id' => null,
            'shopify_product_id' => $product->shopify_id,
            'shopify_variant_id' => null,
            'product_title' => $product->title,
            'variant_title' => 'No current variant',
            'sku' => null,
            'vendor' => $product->vendor,
            'product_type' => $product->type,
            'product_status' => strtolower(trim((string) $product->status)) ?: 'unknown',
            'variant_status' => 'missing',
            'product_created_at' => $product->shopify_created_at ?? $product->created_at,
            'analysis_start_date' => $start->toDateString(),
            'analysis_end_date' => $end->toDateString(),
            'months_analysed' => $run->months_analysed,
            'gross_units_sold' => 0,
            'refunded_units' => 0,
            'net_units_sold' => 0,
            'order_count' => 0,
            'average_units_per_month' => 0,
            'average_units_per_30_days' => 0,
            'months_with_sales' => 0,
            'sales_consistency_percentage' => 0,
            'current_inventory_status' => 'unknown',
            'movement_score' => 0,
            'movement_classification' => 'insufficient_data',
            'recommended_action' => 'insufficient_data',
            'manager_reason' => 'This product has no current variant, so there is not enough information to assess it reliably.',
            'currently_on_sale' => false,
            'has_snapshot_history' => false,
            'data_quality_note' => 'Product has no current local variant, so variant-level sales and inventory matching could not be performed.',
            'created_at' => now(),
            'updated_at' => now(),
        ];
    }

    /**
     * @param array<string,mixed> $snapshots
     * @param array<string,mixed> $sales
     * @return array<string,mixed>
     */
    private function summarizeSnapshots(array $snapshots, array $sales, Carbon $start, Carbon $end): array
    {
        $allDaily = collect($snapshots['daily'] ?? [])->sortKeys();
        $daily = $allDaily
            ->filter(fn ($quantity, string $date): bool => $date >= $start->toDateString() && $date <= $end->toDateString())
            ->sortKeys();

        if ($daily->isEmpty()) {
            return [
                'first_date' => null,
                'days' => null,
                'in_stock_days' => null,
                'out_of_stock_days' => null,
                'opening' => null,
                'average' => null,
                'closing' => null,
                'units_per_30_in_stock_days' => null,
                'source' => null,
            ];
        }

        $inStockDays = $daily->filter(fn ($quantity): bool => (int) $quantity > 0)->count();
        $coveredStart = (string) $daily->keys()->first();
        $coveredEnd = (string) $daily->keys()->last();
        $coveredNet = collect($sales['daily_net'])
            ->filter(fn ($quantity, string $date): bool => $date >= $coveredStart && $date <= $coveredEnd)
            ->sum();

        return [
            'first_date' => (string) $allDaily->keys()->first(),
            'days' => $daily->count(),
            'in_stock_days' => $inStockDays,
            'out_of_stock_days' => $daily->count() - $inStockDays,
            'opening' => (int) $daily->first(),
            'average' => round((float) $daily->average(), 4),
            'closing' => (int) $daily->last(),
            'units_per_30_in_stock_days' => $inStockDays > 0
                ? round(((int) $coveredNet / $inStockDays) * 30, 4)
                : null,
            'source' => $snapshots['source'] ?? null,
        ];
    }

    private function movementScore(
        float $averageMonthly,
        int $net,
        float $consistency,
        ?int $daysSinceLastSale,
        int $orders,
        ?int $currentInventory,
        ?float $snapshotVelocity,
    ): float {
        $weights = (array) config('product_movement.score_weights', []);
        $caps = (array) config('product_movement.normalization_caps', []);
        $components = [
            'average_monthly_units' => $this->ratio($averageMonthly, (float) ($caps['average_monthly_units'] ?? 10)),
            'net_units' => $this->ratio($net, (float) ($caps['net_units'] ?? 60)),
            'sales_consistency' => min(1, max(0, $consistency / 100)),
            'recency' => $daysSinceLastSale === null
                ? 0
                : max(0, 1 - ($daysSinceLastSale / max(1, (int) ($caps['recency_days'] ?? 180)))),
            'order_count' => $this->ratio($orders, (float) ($caps['order_count'] ?? 30)),
            'current_inventory' => $currentInventory === null ? 0.5 : ($currentInventory > 0 ? 1 : 0),
            'snapshot_velocity' => $snapshotVelocity === null
                ? null
                : $this->ratio($snapshotVelocity, (float) ($caps['snapshot_units_per_30_days'] ?? 10)),
        ];

        $score = 0.0;
        $appliedWeight = 0.0;
        foreach ($components as $key => $component) {
            if ($component === null) {
                continue;
            }
            $weight = max(0, (float) ($weights[$key] ?? 0));
            $score += $component * $weight;
            $appliedWeight += $weight;
        }

        return $appliedWeight > 0 ? min(100, max(0, ($score / $appliedWeight) * 100)) : 0;
    }

    /**
     * @param array<string,mixed> $snapshot
     */
    private function classification(
        float $score,
        float $averageMonthly,
        int $net,
        float $consistency,
        ?int $daysSinceLastSale,
        mixed $createdAt,
        Carbon $end,
        array $snapshot,
    ): string {
        $settings = (array) config('product_movement.classification', []);
        $ageDays = $createdAt ? Carbon::parse($createdAt)->diffInDays($end, false) : null;
        if (
            $ageDays !== null
            && $ageDays < (int) ($settings['recent_product_days'] ?? 60)
            && $net < (int) ($settings['recent_product_sales_threshold'] ?? 3)
        ) {
            return 'new_product';
        }

        if ($net <= 0) {
            return 'no_sales';
        }

        if (
            $score >= (float) ($settings['fast_score'] ?? 70)
            && $averageMonthly >= (float) ($settings['fast_average_monthly_units'] ?? 5)
            && $consistency >= (float) ($settings['fast_consistency_percentage'] ?? 60)
            && $daysSinceLastSale !== null
            && $daysSinceLastSale <= (int) ($settings['fast_recent_days'] ?? 45)
        ) {
            return 'fast_moving';
        }

        if (
            $score >= (float) ($settings['medium_score'] ?? 40)
            && $daysSinceLastSale !== null
            && $daysSinceLastSale <= (int) ($settings['medium_recent_days'] ?? 120)
        ) {
            return 'medium_moving';
        }

        return 'slow_moving';
    }

    /**
     * @param array<string,mixed> $snapshot
     * @return array{action:string,reason:string}
     */
    private function managerRecommendation(
        string $classification,
        int $net,
        float $averageMonthly,
        int $monthsWithSales,
        float $monthsAnalysed,
        ?int $currentInventory,
        bool $onSale,
        ?int $daysSinceLastSale,
        mixed $createdAt,
        Carbon $end,
        array $snapshot,
    ): array {
        $period = $this->managerPeriodLabel($monthsAnalysed);
        $stock = $currentInventory === null ? 'an unknown stock quantity' : "{$currentInventory} units remaining";

        if ($classification === 'fast_moving') {
            $needsStock = $currentInventory !== null
                && $currentInventory <= max(2, (int) ceil($averageMonthly));

            return [
                'action' => $needsStock ? 'restock' : 'maintain',
                'reason' => $needsStock
                    ? "Sold {$net} units in {$period} and has {$stock}."
                    : "Sold {$net} units in {$period} and sold in {$monthsWithSales} months.",
            ];
        }

        if ($classification === 'medium_moving') {
            $needsStock = $currentInventory !== null
                && $currentInventory <= max(1, (int) ceil($averageMonthly));

            return [
                'action' => $needsStock ? 'restock' : 'maintain',
                'reason' => 'Sells an average of ' . number_format($averageMonthly, 1)
                    . " units per month and sold in {$monthsWithSales} months.",
            ];
        }

        if ($classification === 'slow_moving') {
            return [
                'action' => $onSale ? 'monitor' : 'promote',
                'reason' => $currentInventory !== null
                    ? "Has {$currentInventory} units available but sold only {$net} units in {$period}"
                        . ($onSale ? ' and is already on sale.' : '.')
                    : "Sold only {$net} units in {$period}"
                        . ($onSale ? ' and is already on sale.' : '.'),
            ];
        }

        if ($classification === 'no_sales') {
            return [
                'action' => 'review',
                'reason' => $currentInventory !== null && $currentInventory > 0
                    ? "Currently has {$currentInventory} units in stock but recorded no sales in {$period}."
                    : "Recorded no sales in {$period}.",
            ];
        }

        if ($classification === 'new_product') {
            $ageDays = $createdAt ? max(0, Carbon::parse($createdAt)->diffInDays($end, false)) : null;

            return [
                'action' => 'insufficient_data',
                'reason' => $ageDays === null
                    ? 'This is a new product and needs more selling time.'
                    : "This product has only been available for {$ageDays} days.",
            ];
        }

        if ($classification === 'out_of_stock_or_unavailable') {
            return [
                'action' => 'review',
                'reason' => "The product was out of stock for {$snapshot['out_of_stock_days']} of "
                    . "{$snapshot['days']} observed snapshot days.",
            ];
        }

        return [
            'action' => 'insufficient_data',
            'reason' => 'There is not enough order or inventory information to assess this product reliably.',
        ];
    }

    private function managerPeriodLabel(float $months): string
    {
        $rounded = (int) round($months);

        return abs($months - $rounded) < 0.15
            ? "the past {$rounded} " . ($rounded === 1 ? 'month' : 'months')
            : 'the selected period';
    }

    /**
     * @return array<int,string>
     */
    private function qualityNotes(
        ProductMovementReportRun $run,
        Variant $variant,
        Product $product,
        array $sales,
        array $snapshot,
        mixed $createdAt,
    ): array {
        $notes = [];
        $failedRefreshIds = array_map(
            'intval',
            (array) data_get($run->settings, 'shopify_refresh.failed_product_ids', []),
        );
        if (in_array((int) $product->id, $failedRefreshIds, true)) {
            $notes[] = 'Latest Shopify refresh failed for this product; current status, price or inventory may be stale.';
        }
        if (($snapshot['days'] ?? null) === null) {
            $notes[] = 'No inventory snapshot history; stock-availability adjustment was not applied.';
        } else {
            $notes[] = 'Inventory availability uses observed snapshot days only'
                . (($snapshot['source'] ?? null) ? " ({$snapshot['source']})." : '.');
        }
        if (trim((string) $variant->sku) === '') {
            $notes[] = 'SKU is missing; sales matching used Shopify variant ID only.';
        }
        if ($product->shopify_created_at === null && $createdAt !== null) {
            $notes[] = 'Shopify product creation date was unavailable; the catalogue record creation date was used.';
        }
        if (($sales['gross'] ?? 0) === 0) {
            $notes[] = 'No qualifying non-cancelled Shopify order lines in the selected period.';
        }
        if (strtolower(trim((string) $product->status)) === 'archived') {
            $notes[] = 'Product is currently archived.';
        }

        return $notes;
    }

    private function resolveVariantId(array $identity, string $shopifyId, string $sku): ?int
    {
        $shopifyId = trim($shopifyId);
        if ($shopifyId !== '' && isset($identity['shopify'][$shopifyId])) {
            return (int) $identity['shopify'][$shopifyId];
        }

        $sku = $this->normalizeSku($sku);

        return $sku !== '' && array_key_exists($sku, $identity['sku'])
            ? $identity['sku'][$sku]
            : null;
    }

    private function variantTitle(Variant $variant): string
    {
        $values = collect([$variant->option1_value, $variant->option2_value, $variant->option3_value])
            ->map(fn ($value): string => trim((string) $value))
            ->filter()
            ->values();

        return $values->isEmpty() ? 'Default variant' : $values->implode(' / ');
    }

    private function variantStatus(Variant $variant, Product $product): string
    {
        $productStatus = strtolower(trim((string) $product->status));
        if ($productStatus !== 'active') {
            return $productStatus === '' ? 'unknown' : $productStatus;
        }

        if ($variant->shopify_available_for_sale === false) {
            return 'unavailable';
        }

        if ($variant->inventory_tracked === true && (int) ($variant->current_available_quantity ?? $variant->inventory_qty ?? 0) <= 0) {
            return 'out_of_stock';
        }

        return 'active';
    }

    private function inventoryStatus(Variant $variant, ?int $quantity): string
    {
        if ($variant->inventory_tracked === false) {
            return 'untracked';
        }
        if ($variant->inventory_tracked === null || $quantity === null) {
            return 'unknown';
        }

        return $quantity > 0 ? 'in_stock' : 'out_of_stock';
    }

    private function emptySales(): array
    {
        return [
            'gross' => 0,
            'refunded' => 0,
            'net' => 0,
            'orders' => [],
            'months' => [],
            'first' => null,
            'last' => null,
            'daily_net' => [],
        ];
    }

    private function normalizeSku(mixed $sku): string
    {
        return strtoupper(trim((string) $sku));
    }

    private function monthsAnalysed(Carbon $start, Carbon $end): float
    {
        return round(($start->diffInDays($end) + 1) / 30.4375, 2);
    }

    private function calendarMonths(Carbon $start, Carbon $end): int
    {
        return $start->copy()->startOfMonth()->diffInMonths($end->copy()->startOfMonth()) + 1;
    }

    private function ratio(float|int $value, float $cap): float
    {
        return $cap <= 0 ? 0 : min(1, max(0, (float) $value / $cap));
    }
}
