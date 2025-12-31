<?php

namespace App\Filament\Widgets;

use App\Models\SeoMetric;
use App\Models\SeoPeriod;
use Filament\Widgets\ChartWidget;

class SeoMetricsTrendChart extends ChartWidget
{
    protected static ?string $heading = 'SEO Trends (Totals)';
    protected static ?int $sort = 1;

    protected function getData(): array
    {
        $periods = SeoPeriod::query()
            ->orderBy('sort_order')
            ->get();

        $labels = $periods->pluck('label')->all();
        $periodIds = $periods->pluck('id')->all();

        $clicks = [];
        $impressions = [];
        $ctr = [];
        $position = [];

        foreach ($periodIds as $periodId) {
            $totals = SeoMetric::query()
                ->where('period_id', $periodId)
                ->selectRaw('sum(clicks) as clicks, sum(impressions) as impressions')
                ->first();

            $totalClicks = (int) ($totals->clicks ?? 0);
            $totalImpressions = (int) ($totals->impressions ?? 0);
            $clicks[] = $totalClicks;
            $impressions[] = $totalImpressions;
            $ctr[] = $totalImpressions > 0 ? round(($totalClicks / $totalImpressions) * 100, 2) : 0;

            $avgPosition = SeoMetric::query()
                ->where('period_id', $periodId)
                ->avg('position') ?? 0;
            $position[] = round((float) $avgPosition, 2);
        }

        return [
            'datasets' => [
                [
                    'label' => 'Clicks',
                    'data' => $clicks,
                    'borderColor' => '#f59e0b',
                    'backgroundColor' => 'rgba(245, 158, 11, 0.2)',
                    'tension' => 0.3,
                ],
                [
                    'label' => 'Impressions',
                    'data' => $impressions,
                    'borderColor' => '#2563eb',
                    'backgroundColor' => 'rgba(37, 99, 235, 0.2)',
                    'tension' => 0.3,
                ],
                [
                    'label' => 'CTR %',
                    'data' => $ctr,
                    'borderColor' => '#10b981',
                    'backgroundColor' => 'rgba(16, 185, 129, 0.2)',
                    'tension' => 0.3,
                ],
                [
                    'label' => 'Avg Position (lower is better)',
                    'data' => $position,
                    'borderColor' => '#ef4444',
                    'backgroundColor' => 'rgba(239, 68, 68, 0.2)',
                    'tension' => 0.3,
                ],
            ],
            'labels' => $labels,
        ];
    }

    protected function getType(): string
    {
        return 'line';
    }
}
