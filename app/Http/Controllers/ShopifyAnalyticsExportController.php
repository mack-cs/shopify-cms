<?php

namespace App\Http\Controllers;

use App\Services\Shopify\ShopifyAnalyticsExportService;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\StreamedResponse;

class ShopifyAnalyticsExportController extends Controller
{
    public function __construct(
        private readonly ShopifyAnalyticsExportService $exports,
    ) {}

    public function orderLines(Request $request): StreamedResponse
    {
        $dates = $this->dates($request);

        return $this->exports->mlOrderLinesCsv($dates['from'], $dates['to']);
    }

    public function products(): StreamedResponse
    {
        return $this->exports->mlProductsCsv();
    }

    public function inventorySnapshots(Request $request): StreamedResponse
    {
        $dates = $this->dates($request);

        return $this->exports->inventorySnapshotsCsv($dates['from'], $dates['to']);
    }

    public function inventoryEvents(Request $request): StreamedResponse
    {
        $dates = $this->dates($request);

        return $this->exports->inventoryEventsCsv($dates['from'], $dates['to']);
    }

    public function stackComponents(): StreamedResponse
    {
        return $this->exports->stackComponentsCsv();
    }

    /**
     * @return array{from:string,to:string}
     */
    private function dates(Request $request): array
    {
        return $request->validate([
            'from' => ['required', 'date_format:Y-m-d'],
            'to' => ['required', 'date_format:Y-m-d', 'after_or_equal:from'],
        ]);
    }
}
