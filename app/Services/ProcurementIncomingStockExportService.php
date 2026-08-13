<?php

namespace App\Services;

use App\Models\ProcurementPredictionRun;
use Symfony\Component\HttpFoundation\StreamedResponse;

final class ProcurementIncomingStockExportService
{
    public function download(string $runUuid): StreamedResponse
    {
        $run = ProcurementPredictionRun::query()->where('run_uuid', $runUuid)->firstOrFail();
        if ($run->incoming_stock_snapshot_at === null) {
            throw new \RuntimeException('The procurement run does not have an incoming-stock snapshot.');
        }

        return response()->streamDownload(function () use ($run): void {
            $handle = fopen('php://output', 'wb');
            if ($handle === false) {
                throw new \RuntimeException('Unable to open the incoming-stock CSV stream.');
            }
            fputcsv($handle, [
                'shopify_product_id', 'shopify_variant_id', 'sku',
                'quantity_on_order_phase_1', 'quantity_on_order_phase_2',
                'quantity_on_order_phase_3', 'total_quantity_on_order',
                'quantity_on_order', 'source_sheet', 'source_changed_at',
            ]);
            $run->incomingStockInputs()->orderBy('id')->chunkById(1000, function ($rows) use ($handle): void {
                foreach ($rows as $row) {
                    fputcsv($handle, [
                        $row->shopify_product_id, $row->shopify_variant_id, $row->sku,
                        $row->quantity_on_order_phase_1, $row->quantity_on_order_phase_2,
                        $row->quantity_on_order_phase_3, $row->total_quantity_on_order,
                        $row->total_quantity_on_order, $row->source_sheet,
                        $row->source_changed_at?->toIso8601String(),
                    ]);
                }
            });
            fclose($handle);
        }, "incoming_stock_{$run->run_uuid}.csv", [
            'Content-Type' => 'text/csv; charset=UTF-8',
            'Cache-Control' => 'no-store, no-cache',
            'X-Procurement-Run-Uuid' => $run->run_uuid,
            'X-Incoming-Stock-Input-Hash' => (string) $run->incoming_stock_input_hash,
        ]);
    }
}
