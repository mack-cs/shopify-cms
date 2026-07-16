<?php

namespace App\Services;

use App\Models\SeoMetric;
use App\Models\SeoPeriod;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

final class SearchConsoleMetricImportService
{
    /**
     * @param iterable<int, array<string, mixed>> $rows
     * @return array{period_id:int,total:int,imported:int,skipped:int}
     */
    public function importRows(iterable $rows, string $entityType, string $label, ?string $startDate = null, ?string $endDate = null): array
    {
        $entityType = strtolower(trim($entityType));
        if (!in_array($entityType, ['site', 'query', 'page'], true)) {
            throw new \InvalidArgumentException('Search Console import type must be site, query, or page.');
        }

        $period = $this->period($label, $startDate, $endDate);
        $total = 0;
        $imported = 0;
        $skipped = 0;

        DB::transaction(function () use ($rows, $entityType, $period, &$total, &$imported, &$skipped): void {
            foreach ($rows as $row) {
                $total++;

                $entity = trim((string) ($row['entity'] ?? ''));
                if ($entityType === 'site' && $entity === '') {
                    $entity = 'site';
                }

                if ($entity === '') {
                    $skipped++;
                    continue;
                }

                $clicks = max(0, (int) ($row['clicks'] ?? 0));
                $impressions = max(0, (int) ($row['impressions'] ?? 0));
                $position = max(0, (float) ($row['position'] ?? 0));
                $ctr = $impressions > 0
                    ? ($clicks / $impressions) * 100
                    : max(0, (float) ($row['ctr'] ?? 0));

                SeoMetric::query()->updateOrCreate(
                    [
                        'period_id' => $period->id,
                        'entity_type' => $entityType,
                        'entity_hash' => SeoMetric::hashEntityValue($entity),
                    ],
                    [
                        'entity_value' => $entity,
                        'clicks' => $clicks,
                        'impressions' => $impressions,
                        'ctr' => number_format($ctr, 2, '.', ''),
                        'position' => number_format($position, 2, '.', ''),
                    ],
                );

                $imported++;
            }
        });

        return [
            'period_id' => $period->id,
            'total' => $total,
            'imported' => $imported,
            'skipped' => $skipped,
        ];
    }

    private function period(string $label, ?string $startDate, ?string $endDate): SeoPeriod
    {
        $label = trim($label);
        if ($label === '') {
            $label = $startDate && $endDate
                ? Carbon::parse($startDate)->format('M Y') . ' - ' . Carbon::parse($endDate)->format('M Y')
                : 'Search Console ' . now()->format('Y-m-d H:i');
        }

        $sortOrder = $startDate
            ? (int) Carbon::parse($startDate)->format('Ymd')
            : ((int) SeoPeriod::query()->max('sort_order')) + 1;

        return SeoPeriod::query()->updateOrCreate(
            ['label' => $label],
            [
                'start_date' => $startDate ? Carbon::parse($startDate)->toDateString() : null,
                'end_date' => $endDate ? Carbon::parse($endDate)->toDateString() : null,
                'sort_order' => $sortOrder,
            ],
        );
    }
}
