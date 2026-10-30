<?php

namespace App\Http\Controllers;

use App\Services\EngravedBeads\EngravedBeadProcurementContract;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\StreamedResponse;

final class EngravedBeadProcurementContractController extends Controller
{
    public function __invoke(EngravedBeadProcurementContract $contract): JsonResponse
    {
        return response()->json($contract->export());
    }

    public function consumption(Request $request, EngravedBeadProcurementContract $contract): StreamedResponse
    {
        $dates = $request->validate(['from' => ['required', 'date_format:Y-m-d'], 'to' => ['required', 'date_format:Y-m-d', 'after_or_equal:from']]);

        return response()->streamDownload(function () use ($contract, $dates): void {
            $output = fopen('php://output', 'w');
            fputcsv($output, ['event_id', 'action', 'status', 'occurred_at', 'order_name', 'source_variant_id',
                'source_inventory_item_id', 'source_location_id', 'sku', 'quantity', 'units']);
            foreach ($contract->consumption($dates['from'], $dates['to']) as $row) {
                fputcsv($output, array_values($row));
            }
            fclose($output);
        }, 'source-consumption.csv', ['Content-Type' => 'text/csv']);
    }
}
