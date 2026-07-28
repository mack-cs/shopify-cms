<?php

namespace App\Filament\Widgets;

use App\Services\SeoReportService;
use Filament\Widgets\Concerns\InteractsWithPageFilters;
use Filament\Widgets\StatsOverviewWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;

class SeoMetricsStats extends StatsOverviewWidget
{
    use InteractsWithPageFilters;

    protected static ?int $sort = -2;

    protected function getStats(): array
    {
        $report = app(SeoReportService::class);
        $periodId = $this->periodId();
        $entityType = $this->entityType();
        $latest = $report->comparisonForPeriod($periodId, $entityType);

        if (($latest['current']['label'] ?? 'No period') === 'No period') {
            return [];
        }

        $current = $latest['current'];
        $previous = $latest['previous'];
        $trend = $report->trendRowsForPeriod($periodId, $entityType, 12);

        return [
            $this->volumeStat('Clicks', $current['clicks'], $previous['clicks'] ?? null)
                ->chart($trend->pluck('clicks')->all()),
            $this->volumeStat('Impressions', $current['impressions'], $previous['impressions'] ?? null)
                ->chart($trend->pluck('impressions')->all()),
            $this->ctrStat($current['ctr'], $previous['ctr'] ?? null)
                ->chart($trend->pluck('ctr')->all()),
            $this->positionStat($current['position'], $previous['position'] ?? null)
                ->chart($trend->pluck('position')->all()),
        ];
    }

    private function volumeStat(string $label, float|int $current, float|int|null $previous): Stat
    {
        $stat = Stat::make($label, number_format($current));
        if ($previous === null) {
            return $stat->description('No previous period');
        }

        $delta = $current - $previous;
        $percent = (float) $previous === 0.0 ? null : ($delta / $previous) * 100;
        $description = 'Prev: '.number_format($previous)
            .' · '.$this->signed($delta)
            .($percent === null ? '' : ' ('.$this->signed($percent, 1).'%)');

        return $stat
            ->description($description)
            ->descriptionIcon($delta >= 0 ? 'heroicon-m-arrow-trending-up' : 'heroicon-m-arrow-trending-down')
            ->color($delta >= 0 ? 'success' : 'danger');
    }

    private function ctrStat(float|int $current, float|int|null $previous): Stat
    {
        $stat = Stat::make('CTR', number_format($current, 2).'%');
        if ($previous === null) {
            return $stat->description('No previous period');
        }

        $delta = $current - $previous;

        return $stat
            ->description('Prev: '.number_format($previous, 2).'% · '.$this->signed($delta, 2).' pp')
            ->descriptionIcon($delta >= 0 ? 'heroicon-m-arrow-trending-up' : 'heroicon-m-arrow-trending-down')
            ->color($delta >= 0 ? 'success' : 'danger');
    }

    private function positionStat(float|int $current, float|int|null $previous): Stat
    {
        $stat = Stat::make('Average Position', number_format($current, 2));
        if ($previous === null) {
            return $stat->description('No previous period');
        }

        $delta = $current - $previous;
        $improved = $delta <= 0;

        return $stat
            ->description(
                'Prev: '.number_format($previous, 2)
                .' · '.($improved ? 'Improved by ' : 'Dropped by ')
                .number_format(abs($delta), 2)
            )
            ->descriptionIcon($improved ? 'heroicon-m-arrow-trending-up' : 'heroicon-m-arrow-trending-down')
            ->color($improved ? 'success' : 'danger');
    }

    private function signed(float|int $value, int $decimals = 0): string
    {
        return ($value > 0 ? '+' : '').number_format($value, $decimals);
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
