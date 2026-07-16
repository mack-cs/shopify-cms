<?php

use App\Models\SeoMetric;
use App\Models\SeoPeriod;
use App\Services\SearchConsoleCsvImporter;
use App\Services\SeoReportService;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('uses impression weighted average position for period reports', function (): void {
    $period = SeoPeriod::query()->create([
        'label' => 'Jan 2026',
        'start_date' => '2026-01-01',
        'end_date' => '2026-01-31',
        'sort_order' => 20260101,
    ]);

    SeoMetric::query()->create([
        'period_id' => $period->id,
        'entity_type' => 'query',
        'entity_value' => 'strong query',
        'clicks' => 50,
        'impressions' => 100,
        'ctr' => 50,
        'position' => 1,
    ]);
    SeoMetric::query()->create([
        'period_id' => $period->id,
        'entity_type' => 'query',
        'entity_value' => 'tiny query',
        'clicks' => 0,
        'impressions' => 1,
        'ctr' => 0,
        'position' => 50,
    ]);

    $row = app(SeoReportService::class)->periodAggregates('query')->first();

    expect(round($row['position'], 2))->toBe(1.49)
        ->and(round($row['ctr'], 2))->toBe(49.5);
});

it('prefers site level Search Console totals for dashboard reports', function (): void {
    $period = SeoPeriod::query()->create([
        'label' => 'Jun 2026',
        'start_date' => '2026-06-01',
        'end_date' => '2026-06-30',
        'sort_order' => 20260601,
    ]);

    SeoMetric::query()->create([
        'period_id' => $period->id,
        'entity_type' => 'query',
        'entity_value' => 'leigh avenue',
        'clicks' => 10,
        'impressions' => 100,
        'ctr' => 10,
        'position' => 40,
    ]);

    SeoMetric::query()->create([
        'period_id' => $period->id,
        'entity_type' => 'site',
        'entity_value' => 'site',
        'clicks' => 2750,
        'impressions' => 81500,
        'ctr' => 3.37,
        'position' => 14.6,
    ]);

    $row = app(SeoReportService::class)->periodAggregates()->first();
    $queryRow = app(SeoReportService::class)->periodAggregates('query')->first();

    expect($row['entity_type'])->toBe('site')
        ->and($row['clicks'])->toBe(2750)
        ->and(round($row['position'], 2))->toBe(14.6)
        ->and($queryRow['entity_type'])->toBe('query')
        ->and(round($queryRow['position'], 2))->toBe(40.0);
});

it('compares the latest three SEO periods against the previous three', function (): void {
    foreach (range(1, 6) as $month) {
        $period = SeoPeriod::query()->create([
            'label' => '2026-' . str_pad((string) $month, 2, '0', STR_PAD_LEFT),
            'start_date' => '2026-' . str_pad((string) $month, 2, '0', STR_PAD_LEFT) . '-01',
            'end_date' => '2026-' . str_pad((string) $month, 2, '0', STR_PAD_LEFT) . '-28',
            'sort_order' => 20260000 + ($month * 100) + 1,
        ]);

        SeoMetric::query()->create([
            'period_id' => $period->id,
            'entity_type' => 'query',
            'entity_value' => 'leigh avenue',
            'clicks' => $month * 10,
            'impressions' => $month * 100,
            'ctr' => 10,
            'position' => 10 - $month,
        ]);
    }

    $comparison = app(SeoReportService::class)->latestComparison(3, 'query');

    expect($comparison['current']['clicks'])->toBe(150)
        ->and($comparison['previous']['clicks'])->toBe(60)
        ->and($comparison['current']['label'])->toBe('2026-04 to 2026-06')
        ->and($comparison['previous']['label'])->toBe('2026-01 to 2026-03');
});

it('imports Search Console CSV rows idempotently by period and entity hash', function (): void {
    $path = tempnam(sys_get_temp_dir(), 'search-console-');
    file_put_contents($path, implode("\n", [
        'Query,Clicks,Impressions,CTR,Position',
        'leigh avenue,10,100,10%,2.5',
    ]));

    app(SearchConsoleCsvImporter::class)->import($path, 'query', 'Jan 2026', '2026-01-01', '2026-01-31');

    file_put_contents($path, implode("\n", [
        'Query,Clicks,Impressions,CTR,Position',
        'leigh avenue,12,100,12%,2.25',
    ]));

    $result = app(SearchConsoleCsvImporter::class)->import($path, 'query', 'Jan 2026', '2026-01-01', '2026-01-31');
    $metric = SeoMetric::query()->firstOrFail();

    expect($result['imported'])->toBe(1)
        ->and(SeoPeriod::query()->count())->toBe(1)
        ->and(SeoMetric::query()->count())->toBe(1)
        ->and($metric->clicks)->toBe(12)
        ->and((string) $metric->position)->toBe('2.25')
        ->and($metric->entity_hash)->not->toBeNull();
});

it('imports site level Search Console CSV totals without an entity column', function (): void {
    $path = tempnam(sys_get_temp_dir(), 'search-console-site-');
    file_put_contents($path, implode("\n", [
        'Clicks,Impressions,CTR,Position',
        '2750,81500,3.37%,14.6',
    ]));

    $result = app(SearchConsoleCsvImporter::class)->import($path, 'site', 'Jun 2026', '2026-06-01', '2026-06-30');
    $metric = SeoMetric::query()->firstOrFail();

    expect($result['imported'])->toBe(1)
        ->and($metric->entity_type)->toBe('site')
        ->and($metric->entity_value)->toBe('site')
        ->and($metric->clicks)->toBe(2750)
        ->and((string) $metric->position)->toBe('14.60');
});
