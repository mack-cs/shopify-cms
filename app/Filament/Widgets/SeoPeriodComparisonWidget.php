<?php

namespace App\Filament\Widgets;

use App\Services\SeoReportService;
use Filament\Widgets\Concerns\InteractsWithPageFilters;
use Filament\Widgets\Widget;

class SeoPeriodComparisonWidget extends Widget
{
    use InteractsWithPageFilters;

    protected static string $view = 'filament.widgets.seo-period-comparison-widget';

    protected static ?int $sort = 4;

    protected int|string|array $columnSpan = 'full';

    /**
     * @return array{current:array<string,mixed>,previous:?array<string,mixed>,period_count:int}
     */
    public function latestComparison(): array
    {
        return app(SeoReportService::class)->comparisonForPeriod($this->periodId(), $this->entityType());
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function rows(): array
    {
        return app(SeoReportService::class)->historyRowsForPeriod(
            $this->periodId(),
            $this->entityType(),
            8,
        );
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function topPages(): array
    {
        return app(SeoReportService::class)->topEntities($this->periodId(), 'page', 5);
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function topQueries(): array
    {
        return app(SeoReportService::class)->topEntities($this->periodId(), 'query', 5);
    }

    /**
     * @return array{winners:array<int,array<string,mixed>>,losers:array<int,array<string,mixed>>}
     */
    public function movers(): array
    {
        return app(SeoReportService::class)->entityMovers($this->periodId(), 'page', 5);
    }

    /**
     * @return array{ranking:array<int,array<string,mixed>>,ctr:array<int,array<string,mixed>>}
     */
    public function opportunities(): array
    {
        return app(SeoReportService::class)->opportunities($this->periodId(), 5);
    }

    /**
     * @return array<int, string>
     */
    public function highlights(): array
    {
        $comparison = $this->latestComparison();
        $current = $comparison['current'] ?? [];
        $previous = $comparison['previous'] ?? null;
        if (! $previous) {
            return ['Import an earlier period to unlock performance comparisons and movement insights.'];
        }

        $clickDelta = (int) ($current['clicks'] ?? 0) - (int) ($previous['clicks'] ?? 0);
        $impressionDelta = (int) ($current['impressions'] ?? 0) - (int) ($previous['impressions'] ?? 0);
        $positionDelta = (float) ($current['position'] ?? 0) - (float) ($previous['position'] ?? 0);
        $movers = $this->movers();
        $highlights = [
            'Organic clicks '.($clickDelta >= 0 ? 'increased' : 'decreased').' by '.number_format(abs($clickDelta)).'.',
            'Search impressions '.($impressionDelta >= 0 ? 'increased' : 'decreased').' by '.number_format(abs($impressionDelta)).'.',
            $positionDelta <= 0
                ? 'Average ranking improved by '.number_format(abs($positionDelta), 2).' positions.'
                : 'Average ranking dropped by '.number_format(abs($positionDelta), 2).' positions.',
        ];

        $winner = $movers['winners'][0] ?? null;
        if ($winner) {
            $highlights[] = $winner['label'].' delivered the largest page gain: +'
                .number_format($winner['clicks_delta']).' clicks.';
        }

        return $highlights;
    }

    public function formatNumber(float|int|null $value): string
    {
        return number_format((float) ($value ?? 0));
    }

    public function formatPercent(float|int|null $value): string
    {
        return number_format((float) ($value ?? 0), 2).'%';
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

        return $prefix.$formatted;
    }

    public function formatDeltaPercent(float|int|null $value): string
    {
        if ($value === null) {
            return '-';
        }

        return $this->formatDelta($value, 1).'%';
    }

    public function deltaColor(float|int|null $value, bool $inverse = false): string
    {
        if ($value === null || (float) $value === 0.0) {
            return 'text-gray-500';
        }

        $improved = $inverse ? $value < 0 : $value > 0;

        return $improved ? 'text-green-600' : 'text-red-600';
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
