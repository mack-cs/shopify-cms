<?php

namespace App\Filament\Resources\ProductInventorySnapshotResource\Widgets;

use App\Models\ProductInventorySnapshot;
use Carbon\CarbonImmutable;
use Filament\Widgets\ChartWidget;

class InventoryAvailabilityTrendChart extends ChartWidget
{
    protected static ?string $heading = 'Inventory Availability Trend';
    protected static ?string $description = 'Daily product counts using each product\'s latest snapshot for that date.';
    protected static string $color = 'danger';

    protected function getType(): string
    {
        return 'line';
    }

    protected function getData(): array
    {
        $start = CarbonImmutable::today()->subDays(29);
        $snapshots = ProductInventorySnapshot::query()
            ->where('checked_date', '>=', $start->toDateString())
            ->orderBy('checked_date')
            ->orderBy('product_id')
            ->orderBy('checked_at')
            ->orderBy('id')
            ->get();

        $latestByDateAndProduct = [];

        foreach ($snapshots as $snapshot) {
            if (!$snapshot instanceof ProductInventorySnapshot) {
                continue;
            }

            $date = $snapshot->checkedDateLabel();
            $productKey = $snapshot->product_id
                ? 'product:' . $snapshot->product_id
                : 'deleted:' . ($snapshot->product_shopify_id ?: $snapshot->product_handle ?: $snapshot->id);

            $latestByDateAndProduct[$date][$productKey] = $snapshot;
        }

        $labels = [];
        $outOfStock = [];
        $unsellable = [];

        for ($offset = 0; $offset < 30; $offset++) {
            $date = $start->addDays($offset)->toDateString();
            $labels[] = $start->addDays($offset)->format('M j');

            $dailySnapshots = collect($latestByDateAndProduct[$date] ?? []);
            $outOfStock[] = $dailySnapshots
                ->filter(fn (ProductInventorySnapshot $snapshot): bool => (bool) $snapshot->is_out_of_stock)
                ->count();
            $unsellable[] = $dailySnapshots
                ->filter(fn (ProductInventorySnapshot $snapshot): bool => !$snapshot->is_sellable)
                ->count();
        }

        return [
            'datasets' => [
                [
                    'label' => 'Out of stock',
                    'data' => $outOfStock,
                    'borderColor' => '#dc2626',
                    'backgroundColor' => 'rgba(220, 38, 38, 0.15)',
                ],
                [
                    'label' => 'Unsellable',
                    'data' => $unsellable,
                    'borderColor' => '#f59e0b',
                    'backgroundColor' => 'rgba(245, 158, 11, 0.15)',
                ],
            ],
            'labels' => $labels,
        ];
    }
}
