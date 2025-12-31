<?php

namespace App\Filament\Widgets;

use App\Models\SeoMetric;
use App\Models\SeoPeriod;
use Filament\Widgets\ChartWidget;

class SeoTopEntitiesStackedChart extends ChartWidget
{
    protected static ?string $heading = 'Top Contributors (Clicks)';
    protected static ?int $sort = 2;

    public ?string $filter = 'query';

    protected function getFilters(): array
    {
        return [
            'query' => 'Queries',
            'page' => 'Pages',
        ];
    }

    protected function getData(): array
    {
        $periods = SeoPeriod::query()
            ->orderBy('sort_order')
            ->get();

        $labels = $periods->pluck('label')->all();
        $periodIds = $periods->pluck('id')->all();

        $entityType = $this->filter ?? 'query';
        $topEntities = SeoMetric::query()
            ->where('entity_type', $entityType)
            ->selectRaw('entity_value, sum(clicks) as total_clicks')
            ->groupBy('entity_value')
            ->orderByDesc('total_clicks')
            ->limit(6)
            ->pluck('entity_value')
            ->all();

        $colors = [
            '#f59e0b',
            '#2563eb',
            '#10b981',
            '#ef4444',
            '#8b5cf6',
            '#14b8a6',
        ];

        $datasets = [];
        foreach ($topEntities as $index => $entity) {
            $data = [];
            foreach ($periodIds as $periodId) {
                $clicks = SeoMetric::query()
                    ->where('period_id', $periodId)
                    ->where('entity_type', $entityType)
                    ->where('entity_value', $entity)
                    ->sum('clicks');
                $data[] = (int) $clicks;
            }

            $datasets[] = [
                'label' => $entity,
                'data' => $data,
                'backgroundColor' => $colors[$index % count($colors)],
                'stack' => 'clicks',
            ];
        }

        return [
            'datasets' => $datasets,
            'labels' => $labels,
        ];
    }

    protected function getType(): string
    {
        return 'bar';
    }

    protected function getOptions(): array
    {
        return [
            'scales' => [
                'x' => [
                    'stacked' => true,
                ],
                'y' => [
                    'stacked' => true,
                ],
            ],
        ];
    }
}
