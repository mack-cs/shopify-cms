<?php

namespace App\Filament\Widgets;

use App\Services\SeoReportService;
use Filament\Widgets\ChartWidget;

class SeoTopEntitiesStackedChart extends ChartWidget
{
    protected static ?string $heading = 'Top Contributors (Clicks)';
    protected static ?int $sort = 2;

    public ?string $filter = 'query';

    public static function canView(): bool
    {
        return false;
    }

    protected function getFilters(): array
    {
        return [
            'query' => 'Queries',
            'page' => 'Pages',
        ];
    }

    protected function getData(): array
    {
        return app(SeoReportService::class)->topEntitiesData($this->filter ?? 'query', 6, 6);
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
