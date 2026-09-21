<?php

namespace App\Services;

use App\Models\SeoMetric;
use App\Models\SeoPeriod;
use Illuminate\Support\Collection;

final class SeoReportService
{
    private const ENTITY_TYPES = ['site', 'query', 'page'];

    /**
     * @return Collection<int, array<string, mixed>>
     */
    public function periodAggregates(?string $entityType = null, ?int $limit = null): Collection
    {
        $entityType = $this->requestedEntityType($entityType);

        $periods = SeoPeriod::query()
            ->orderBy('sort_order')
            ->orderBy('start_date')
            ->orderBy('id')
            ->get();

        if ($limit !== null && $limit > 0) {
            $periods = $periods->slice(max(0, $periods->count() - $limit))->values();
        }

        $metricsQuery = SeoMetric::query()
            ->selectRaw('period_id, entity_type, SUM(clicks) as clicks, SUM(impressions) as impressions, SUM(position * impressions) as weighted_position_sum')
            ->whereIn('period_id', $periods->pluck('id'))
            ->groupBy('period_id', 'entity_type');

        if ($entityType !== null) {
            $metricsQuery->where('entity_type', $entityType);
        } else {
            $metricsQuery->whereIn('entity_type', self::ENTITY_TYPES);
        }

        $metrics = $metricsQuery
            ->get()
            ->groupBy('period_id');

        return $periods
            ->map(function (SeoPeriod $period) use ($metrics, $entityType): array {
                $row = $this->preferredAggregateRow($metrics->get($period->id, collect()), $entityType);
                $clicks = (int) ($row->clicks ?? 0);
                $impressions = (int) ($row->impressions ?? 0);
                $weightedPositionSum = (float) ($row->weighted_position_sum ?? 0);

                return $this->summary([
                    'period_id' => $period->id,
                    'entity_type' => $row->entity_type ?? $entityType,
                    'label' => $period->label,
                    'start_date' => $period->start_date?->toDateString(),
                    'end_date' => $period->end_date?->toDateString(),
                    'clicks' => $clicks,
                    'impressions' => $impressions,
                    'weighted_position_sum' => $weightedPositionSum,
                ]);
            })
            ->values();
    }

    /**
     * @return array{current:array<string,mixed>,previous:?array<string,mixed>,period_count:int}
     */
    public function latestComparison(int $periodCount = 1, ?string $entityType = null): array
    {
        $periodCount = max(1, $periodCount);
        $entityType = $this->requestedEntityType($entityType);

        $periods = SeoPeriod::query()
            ->orderBy('sort_order')
            ->orderBy('start_date')
            ->orderBy('id')
            ->get();

        $currentStart = max(0, $periods->count() - $periodCount);
        $previousEnd = $currentStart;
        $previousStart = max(0, $previousEnd - $periodCount);

        $currentPeriods = $periods
            ->slice($currentStart)
            ->values();
        $previousPeriods = $periods
            ->slice($previousStart, $previousEnd - $previousStart)
            ->values();

        return [
            'current' => $this->aggregatePeriodIds($currentPeriods->pluck('id')->all(), $entityType, $this->periodGroupLabel($currentPeriods)),
            'previous' => $previousPeriods->isEmpty()
                ? null
                : $this->aggregatePeriodIds($previousPeriods->pluck('id')->all(), $entityType, $this->periodGroupLabel($previousPeriods)),
            'period_count' => $periodCount,
        ];
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function monthToMonthRows(int $limit = 12, ?string $entityType = null): array
    {
        $rows = $this->periodAggregates($entityType, $limit)->values();

        return $rows
            ->map(function (array $row, int $index) use ($rows): array {
                $previous = $index > 0 ? $rows[$index - 1] : null;

                return array_merge($row, [
                    'clicks_delta' => $previous ? $row['clicks'] - $previous['clicks'] : null,
                    'clicks_percent_delta' => $previous ? $this->percentDelta($row['clicks'], $previous['clicks']) : null,
                    'impressions_delta' => $previous ? $row['impressions'] - $previous['impressions'] : null,
                    'impressions_percent_delta' => $previous ? $this->percentDelta($row['impressions'], $previous['impressions']) : null,
                    'ctr_delta' => $previous ? $row['ctr'] - $previous['ctr'] : null,
                    'position_delta' => $previous ? $row['position'] - $previous['position'] : null,
                ]);
            })
            ->reverse()
            ->values()
            ->all();
    }

    /**
     * @return array{labels:array<int,string>,datasets:array<int,array<string,mixed>>}
     */
    public function trendData(?string $entityType = null, ?int $limit = null): array
    {
        $rows = $this->periodAggregates($entityType, $limit);

        return [
            'labels' => $rows->pluck('label')->all(),
            'datasets' => [
                [
                    'label' => 'Clicks',
                    'data' => $rows->pluck('clicks')->all(),
                    'yAxisID' => 'volume',
                    'borderColor' => '#d97706',
                    'backgroundColor' => 'rgba(217, 119, 6, 0.16)',
                    'tension' => 0.28,
                ],
                [
                    'label' => 'Impressions',
                    'data' => $rows->pluck('impressions')->all(),
                    'yAxisID' => 'volume',
                    'borderColor' => '#2563eb',
                    'backgroundColor' => 'rgba(37, 99, 235, 0.14)',
                    'tension' => 0.28,
                ],
                [
                    'label' => 'Avg Position',
                    'data' => $rows->pluck('position')->map(fn (float $value): float => round($value, 2))->all(),
                    'yAxisID' => 'position',
                    'borderColor' => '#dc2626',
                    'backgroundColor' => 'rgba(220, 38, 38, 0.12)',
                    'tension' => 0.28,
                ],
            ],
        ];
    }

    /**
     * @return array{labels:array<int,string>,datasets:array<int,array<string,mixed>>}
     */
    public function topEntitiesData(string $entityType = 'query', int $entityLimit = 6, int $periodLimit = 6): array
    {
        $entityType = in_array($entityType, ['query', 'page'], true) ? $entityType : 'query';

        $periods = SeoPeriod::query()
            ->orderByDesc('sort_order')
            ->orderByDesc('start_date')
            ->orderByDesc('id')
            ->limit($periodLimit)
            ->get()
            ->reverse()
            ->values();

        $periodIds = $periods->pluck('id')->all();
        $labels = $periods->pluck('label')->all();

        $entities = SeoMetric::query()
            ->where('entity_type', $entityType)
            ->whereIn('period_id', $periodIds)
            ->selectRaw('entity_value, SUM(clicks) as total_clicks')
            ->groupBy('entity_value')
            ->orderByDesc('total_clicks')
            ->limit($entityLimit)
            ->pluck('entity_value')
            ->all();

        $rows = SeoMetric::query()
            ->where('entity_type', $entityType)
            ->whereIn('period_id', $periodIds)
            ->whereIn('entity_value', $entities)
            ->selectRaw('period_id, entity_value, SUM(clicks) as clicks')
            ->groupBy('period_id', 'entity_value')
            ->get()
            ->groupBy('entity_value');

        $colors = ['#d97706', '#2563eb', '#059669', '#dc2626', '#7c3aed', '#0891b2'];

        $datasets = [];
        foreach ($entities as $index => $entity) {
            $entityRows = $rows->get($entity, collect())->keyBy('period_id');
            $datasets[] = [
                'label' => $entity,
                'data' => array_map(fn (int $periodId): int => (int) ($entityRows->get($periodId)->clicks ?? 0), $periodIds),
                'backgroundColor' => $colors[$index % count($colors)],
                'stack' => 'clicks',
            ];
        }

        return [
            'labels' => $labels,
            'datasets' => $datasets,
        ];
    }

    /**
     * @param array<int, int> $periodIds
     * @return array<string, mixed>
     */
    private function aggregatePeriodIds(array $periodIds, ?string $entityType, string $label): array
    {
        if ($periodIds === []) {
            return $this->summary(['label' => $label]);
        }

        $rows = $this->periodAggregates($entityType)
            ->whereIn('period_id', $periodIds);

        return $this->summary([
            'label' => $label,
            'clicks' => (int) $rows->sum('clicks'),
            'impressions' => (int) $rows->sum('impressions'),
            'weighted_position_sum' => (float) $rows->sum('weighted_position_sum'),
        ]);
    }

    /**
     * @param array<string, mixed> $data
     * @return array<string, mixed>
     */
    private function summary(array $data): array
    {
        $clicks = (int) ($data['clicks'] ?? 0);
        $impressions = (int) ($data['impressions'] ?? 0);
        $position = $impressions > 0
            ? ((float) ($data['weighted_position_sum'] ?? 0) / $impressions)
            : 0.0;

        return array_merge($data, [
            'clicks' => $clicks,
            'impressions' => $impressions,
            'ctr' => $impressions > 0 ? ($clicks / $impressions) * 100 : 0.0,
            'position' => $position,
        ]);
    }

    /**
     * @param Collection<int, SeoPeriod> $periods
     */
    private function periodGroupLabel(Collection $periods): string
    {
        if ($periods->isEmpty()) {
            return 'No period';
        }

        if ($periods->count() === 1) {
            return (string) $periods->first()->label;
        }

        return $periods->first()->label . ' to ' . $periods->last()->label;
    }

    private function requestedEntityType(?string $entityType): ?string
    {
        $entityType = strtolower(trim((string) $entityType));
        if (in_array($entityType, self::ENTITY_TYPES, true)) {
            return $entityType;
        }

        return null;
    }

    /**
     * @param Collection<int, object> $rows
     */
    private function preferredAggregateRow(Collection $rows, ?string $requestedEntityType): ?object
    {
        if ($requestedEntityType !== null) {
            return $rows->first();
        }

        foreach (self::ENTITY_TYPES as $entityType) {
            $row = $rows->firstWhere('entity_type', $entityType);
            if ($row !== null) {
                return $row;
            }
        }

        return null;
    }

    private function percentDelta(float|int $current, float|int $previous): ?float
    {
        if ((float) $previous === 0.0) {
            return null;
        }

        return (($current - $previous) / $previous) * 100;
    }
}
