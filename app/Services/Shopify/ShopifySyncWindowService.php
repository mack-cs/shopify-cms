<?php

namespace App\Services\Shopify;

use Carbon\CarbonImmutable;
use Illuminate\Support\Carbon;

final class ShopifySyncWindowService
{
    /**
     * @return array{business_date:string,timezone:string,reporting_start:CarbonImmutable,reporting_end:CarbonImmutable,window_start:CarbonImmutable,window_end:CarbonImmutable,lookback_days:int}
     */
    public function forBusinessDate(string|\DateTimeInterface $businessDate, ?int $lookbackDays = null): array
    {
        $timezone = (string) config('shopify_sync.timezone', 'Africa/Johannesburg');
        $lookbackDays = max(1, $lookbackDays ?? (int) config('shopify_sync.orders.lookback_days', 3));

        $date = $businessDate instanceof \DateTimeInterface
            ? CarbonImmutable::instance($businessDate)->setTimezone($timezone)->startOfDay()
            : CarbonImmutable::parse($businessDate, $timezone)->startOfDay();

        $reportingStart = $date;
        $reportingEnd = $date->addDay();

        return [
            'business_date' => $date->toDateString(),
            'timezone' => $timezone,
            'reporting_start' => $reportingStart,
            'reporting_end' => $reportingEnd,
            'window_start' => $reportingStart->subDays($lookbackDays - 1),
            'window_end' => $reportingEnd,
            'lookback_days' => $lookbackDays,
        ];
    }

    public function yesterdayBusinessDate(): string
    {
        return Carbon::now((string) config('shopify_sync.timezone', 'Africa/Johannesburg'))
            ->subDay()
            ->toDateString();
    }
}
