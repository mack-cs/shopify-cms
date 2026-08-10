<?php

namespace App\Http\Controllers;

use App\Services\Shopify\ShopifyAnalyticsExportService;
use App\Services\ProductMovementMlExportService;
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

    public function productMovement(Request $request, ProductMovementMlExportService $exports): StreamedResponse
    {
        $validated = $request->validate([
            'run_id' => ['nullable', 'integer', 'min:1'],
        ]);

        return $exports->download(isset($validated['run_id']) ? (int) $validated['run_id'] : null);
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
