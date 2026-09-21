<?php

namespace App\Jobs;

use App\Models\ProcurementSupplierOrderLine;
use App\Models\ProcurementSupplierReceipt;
use App\Services\GoogleSheets\ProcurementSheetSyncService;
use App\Services\Procurement\SupplierOrderSummaryService;
use App\Services\ProductInventorySyncService;
use App\Services\Shopify\ShopifyInventoryAdjustmentService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\DB;

class ProcessSupplierReceiptJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 1;

    public int $timeout = 600;

    public function __construct(public readonly int $receiptId) {}

    public function handle(ShopifyInventoryAdjustmentService $shopify, ProductInventorySyncService $inventory, SupplierOrderSummaryService $summary, ProcurementSheetSyncService $sheets): void
    {
        $receipt = ProcurementSupplierReceipt::query()->with('line.variant')->findOrFail($this->receiptId);
        if ($receipt->status !== 'succeeded') {
            if ($receipt->shopify_adjustment_started_at !== null) {
                if ($receipt->status !== 'manual_review') {
                    $receipt->update([
                        'status' => 'manual_review',
                        'error' => $receipt->error ?: 'Shopify adjustment outcome is ambiguous; automatic retry was blocked to prevent double receiving.',
                    ]);
                }

                return;
            }
            try {
                $locationId = $shopify->resolveLocationId($receipt->line->variant);
            } catch (\Throwable $exception) {
                $receipt->update(['status' => 'pending', 'error' => $exception->getMessage()]);
                throw $exception;
            }
            $claimed = ProcurementSupplierReceipt::query()->whereKey($receipt->id)->whereNull('shopify_adjustment_started_at')
                ->update(['status' => 'processing', 'shopify_adjustment_started_at' => now(), 'error' => null]);
            if ($claimed !== 1) {
                return;
            }
            $receipt->refresh()->load('line.variant');
            try {
                $shopify->increaseAvailable(
                    $receipt->line->variant,
                    $receipt->quantity_received,
                    $receipt->shopify_reference_uri,
                    $locationId,
                );
                $receipt->update(['status' => 'succeeded', 'shopify_adjusted_at' => now()]);
            } catch (\Throwable $e) {
                $receipt->update(['status' => 'manual_review', 'error' => $e->getMessage()]);
                throw $e;
            }
        }

        try {
            $line = $receipt->line()->with('variant')->firstOrFail();
            DB::transaction(function () use ($line, $receipt): void {
                $locked = ProcurementSupplierOrderLine::query()->lockForUpdate()->findOrFail($line->id);
                $received = (int) $locked->receipts()->where('status', 'succeeded')->sum('quantity_received');
                if ($received >= (int) $locked->quantity_ordered) {
                    $locked->update([
                        'status' => 'completed',
                        'completed_at' => now(),
                        'updated_by' => $receipt->created_by,
                    ]);
                }
            });
            $result = $inventory->refreshVariants(collect([$line->variant]), $receipt->created_by);
            if (($result['failed'] ?? 0) > 0) {
                throw new \RuntimeException(implode('; ', $result['failures'] ?? ['Shopify refresh failed.']));
            }
            // A receipt transfers quantity from WIP into Shopify inventory. Refresh
            // Shopify first so the recommendation never sees the WIP reduction
            // without the corresponding increase in actual inventory.
            $summary->refreshVariant(
                $line->variant->fresh(['product', 'procurementIncomingStock']),
                $receipt->created_by,
                $receipt->source.':receipt',
            );
            $sheets->publishOperational([$line->variant_id]);
            $receipt->update(['post_process_status' => 'completed', 'processed_at' => now(), 'error' => null]);
        } catch (\Throwable $e) {
            $receipt->update(['post_process_status' => 'failed', 'error' => $e->getMessage()]);
            throw $e;
        }
    }
}
