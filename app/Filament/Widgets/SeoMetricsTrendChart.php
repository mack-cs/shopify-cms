<?php

namespace App\Filament\Widgets;

use App\Services\SeoReportService;
use Filament\Widgets\ChartWidget;

class SeoMetricsTrendChart extends ChartWidget
{
    protected static ?string $heading = 'Monthly SEO Trend';
    protected static ?int $sort = 1;

    protected function getData(): array
    {
        return app(SeoReportService::class)->trendData(limit: 12);
    }

    protected function getType(): string
    {
        return 'line';
    }

    protected function getOptions(): array
    {
        return [
            'interaction' => [
                'mode' => 'index',
                'intersect' => false,
            ],
            'scales' => [
                'volume' => [
                    'type' => 'linear',
                    'position' => 'left',
                    'beginAtZero' => true,
                    'title' => [
                        'display' => true,
                        'text' => 'Clicks / Impressions',
                    ],
                ],
                'position' => [
                    'type' => 'linear',
                    'position' => 'right',
                    'reverse' => true,
                    'grid' => [
                        'drawOnChartArea' => false,
                    ],
                    'title' => [
                        'display' => true,
                        'text' => 'Average position',
                    ],
                ],
            ],
        ];
    }
}
