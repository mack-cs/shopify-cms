<?php

namespace App\Filament\Widgets;

use App\Services\SeoReportService;
use Filament\Widgets\ChartWidget;
use Filament\Widgets\Concerns\InteractsWithPageFilters;

class SeoRankingTrendChart extends ChartWidget
{
    use InteractsWithPageFilters;

    protected static ?string $heading = 'Ranking Trend';

    protected static ?int $sort = 2;

    protected function getData(): array
    {
        return app(SeoReportService::class)->dashboardTrendData(
            'position',
            $this->periodId(),
            $this->entityType(),
            12,
        );
    }

    protected function getType(): string
    {
        return 'line';
    }

    protected function getOptions(): array
    {
        return [
            'interaction' => ['mode' => 'index', 'intersect' => false],
            'scales' => [
                'y' => [
                    'reverse' => true,
                    'title' => ['display' => true, 'text' => 'Average position (lower is better)'],
                ],
            ],
        ];
    }

    private function periodId(): ?int
    {
        $value = $this->filters['period_id'] ?? null;

        return is_numeric($value) ? (int) $value : null;
    }

    private function entityType(): string
    {
        $value = (string) ($this->filters['entity_type'] ?? 'site');

        return in_array($value, ['site', 'query', 'page'], true) ? $value : 'site';
    }
}
