<?php

namespace App\Filament\Widgets;

use App\Models\SeoMetric;
use App\Models\SeoPeriod;
use Filament\Widgets\StatsOverviewWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;

class SeoMetricsStats extends StatsOverviewWidget
{
    protected function getStats(): array
    {
        $periods = SeoPeriod::query()
            ->orderBy('sort_order')
            ->pluck('id', 'label');

        if ($periods->isEmpty()) {
            return [];
        }

        $periodIds = $periods->values()->all();
        $currentPeriodId = end($periodIds);
        $previousPeriodId = count($periodIds) > 1 ? $periodIds[count($periodIds) - 2] : null;

        $current = $this->aggregateMetrics($currentPeriodId);
        $previous = $previousPeriodId ? $this->aggregateMetrics($previousPeriodId) : null;

        return [
            $this->buildStat('Clicks', $current['clicks'], $previous['clicks'] ?? null),
            $this->buildStat('Impressions', $current['impressions'], $previous['impressions'] ?? null),
            $this->buildStat('CTR %', $current['ctr'], $previous['ctr'] ?? null, decimals: 2),
            $this->buildStat('Avg Position', $current['position'], $previous['position'] ?? null, decimals: 2, inverse: true),
        ];
    }

    private function aggregateMetrics(int $periodId): array
    {
        $totals = SeoMetric::query()
            ->where('period_id', $periodId)
            ->selectRaw('sum(clicks) as clicks, sum(impressions) as impressions')
            ->first();

        $ctr = 0.0;
        if ($totals && $totals->impressions > 0) {
            $ctr = ($totals->clicks / $totals->impressions) * 100;
        }

        $avgPosition = SeoMetric::query()
            ->where('period_id', $periodId)
            ->avg('position') ?? 0;

        return [
            'clicks' => (int) ($totals->clicks ?? 0),
            'impressions' => (int) ($totals->impressions ?? 0),
            'ctr' => $ctr,
            'position' => (float) $avgPosition,
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
