<?php

namespace App\Filament\Widgets;

use App\Services\SeoReportService;
use Filament\Widgets\Widget;

class SeoPeriodComparisonWidget extends Widget
{
    protected static string $view = 'filament.widgets.seo-period-comparison-widget';

    protected static ?int $sort = 3;

    protected int|string|array $columnSpan = 'full';

    /**
     * @return array{current:array<string,mixed>,previous:?array<string,mixed>,period_count:int}
     */
    public function latestComparison(): array
    {
        return app(SeoReportService::class)->latestComparison(1);
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function rows(): array
    {
        return app(SeoReportService::class)->monthToMonthRows(12);
    }

    public function formatNumber(float|int|null $value): string
    {
        return number_format((float) ($value ?? 0));
    }

    public function formatPercent(float|int|null $value): string
    {
        return number_format((float) ($value ?? 0), 2) . '%';
    }

    public function formatPosition(float|int|null $value): string
    {
        return number_format((float) ($value ?? 0), 2);
    }

    public function formatDelta(float|int|null $value, int $decimals = 0, bool $inverse = false): string
    {
        if ($value === null) {
            return '-';
        }

        $formatted = number_format(abs((float) $value), $decimals);
        $prefix = $value > 0 ? '+' : ($value < 0 ? '-' : '');

        return $prefix . $formatted;
    }

    public function formatDeltaPercent(float|int|null $value): string
    {
        if ($value === null) {
            return '-';
        }

        return $this->formatDelta($value, 1) . '%';
    }

    public function deltaColor(float|int|null $value, bool $inverse = false): string
    {
        if ($value === null || (float) $value === 0.0) {
            return 'text-gray-500';
        }

        $improved = $inverse ? $value < 0 : $value > 0;

        return $improved ? 'text-green-600' : 'text-red-600';
    }
}
