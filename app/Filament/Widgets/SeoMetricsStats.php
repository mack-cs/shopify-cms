<?php

namespace App\Filament\Widgets;

use App\Services\SeoReportService;
use Filament\Widgets\StatsOverviewWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;

class SeoMetricsStats extends StatsOverviewWidget
{
    protected function getStats(): array
    {
        $report = app(SeoReportService::class);
        $latest = $report->latestComparison(1);

        if (($latest['current']['label'] ?? 'No period') === 'No period') {
            return [];
        }

        $current = $latest['current'];
        $previous = $latest['previous'];

        return [
            $this->buildStat('Clicks', $current['clicks'], $previous['clicks'] ?? null),
            $this->buildStat('Impressions', $current['impressions'], $previous['impressions'] ?? null),
            $this->buildStat('CTR %', $current['ctr'], $previous['ctr'] ?? null, decimals: 2),
            $this->buildStat('Avg Position', $current['position'], $previous['position'] ?? null, decimals: 2, inverse: true),
        ];
    }

    private function buildStat(string $label, float|int $current, ?float $previous, int $decimals = 0, bool $inverse = false): Stat
    {
        $formatted = number_format($current, $decimals);
        $stat = Stat::make($label, $formatted);

        if ($previous !== null) {
            $diff = $current - $previous;
            $direction = $diff >= 0 ? 'increase' : 'decrease';

            if ($inverse) {
                $direction = $diff <= 0 ? 'increase' : 'decrease';
            }

            $percent = $previous == 0 ? null : (($diff / $previous) * 100);
            $trend = $percent === null ? null : number_format(abs($percent), 1) . '%';
            if ($trend) {
                $stat = $stat->description(($diff >= 0 ? '+' : '-') . $trend)
                    ->descriptionIcon($direction === 'increase' ? 'heroicon-m-arrow-trending-up' : 'heroicon-m-arrow-trending-down')
                    ->color($direction === 'increase' ? 'success' : 'danger');
            }
        }

        return $stat;
    }
}
