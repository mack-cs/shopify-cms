<?php

namespace App\Filament\Widgets;

use App\Services\SeoReportService;
use Filament\Widgets\ChartWidget;
use Filament\Widgets\Concerns\InteractsWithPageFilters;

class SeoCtrTrendChart extends ChartWidget
{
    use InteractsWithPageFilters;

    protected static ?string $heading = 'CTR Trend';

    protected static ?int $sort = 3;

    protected function getData(): array
    {
        return app(SeoReportService::class)->dashboardTrendData(
            'ctr',
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
                    'beginAtZero' => true,
                    'title' => ['display' => true, 'text' => 'CTR %'],
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
