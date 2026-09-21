<?php

namespace App\Jobs;

use App\Models\Product;
use App\Models\SaleProductUpdate;
use App\Models\ScheduledJob;
use App\Models\ScheduledJobItem;
use App\Models\Variant;
use App\Services\ProductShopifyUpdater;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

class RunScheduledSaleJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    public function __construct(public int $scheduledJobId)
    {
    }

    public function handle(ProductShopifyUpdater $updater): void
    {
        $job = ScheduledJob::query()->find($this->scheduledJobId);
        if (!$job instanceof ScheduledJob) {
            return;
        }

        if (in_array($job->status, [ScheduledJob::STATUS_COMPLETED, ScheduledJob::STATUS_CANCELLED], true)) {
            return;
        }

        if ($job->scheduled_at !== null && $job->scheduled_at->isFuture()) {
            $this->release((int) max(1, ceil(now()->diffInSeconds($job->scheduled_at, false))));
            return;
        }

        $job->update([
            'status' => ScheduledJob::STATUS_RUNNING,
            'started_at' => $job->started_at ?? now(),
            'error_summary' => null,
        ]);

        $job->items()
            ->with(['saleProductUpdate.product', 'saleProductUpdate.variant'])
            ->where('status', '!=', ScheduledJobItem::STATUS_COMPLETED)
            ->orderBy('id')
            ->chunkById(100, function ($items) use ($updater): void {
                foreach ($items as $item) {
                    if (!$item instanceof ScheduledJobItem) {
                        continue;
                    }

                    $this->processItem($item, $updater);
                }
            });

        $completed = $job->items()->where('status', ScheduledJobItem::STATUS_COMPLETED)->count();
        $failed = $job->items()->where('status', ScheduledJobItem::STATUS_FAILED)->count();
        $skipped = $job->items()->where('status', ScheduledJobItem::STATUS_SKIPPED)->count();

        $job->update([
            'status' => $failed > 0 ? ScheduledJob::STATUS_FAILED : ScheduledJob::STATUS_COMPLETED,
            'completed_at' => now(),
            'succeeded_items' => $completed + $skipped,
            'failed_items' => $failed,
            'error_summary' => $failed > 0
                ? $job->items()
                    ->where('status', ScheduledJobItem::STATUS_FAILED)
                    ->whereNotNull('error_message')
                    ->limit(5)
                    ->pluck('error_message')
                    ->implode(' | ')
                : null,
        ]);

        logger()->info('Scheduled sale product update job finished', [
            'scheduled_job_id' => $job->id,
            'status' => $job->status,
            'completed' => $completed,
            'skipped' => $skipped,
            'failed' => $failed,
        ]);
    }

    private function processItem(ScheduledJobItem $item, ProductShopifyUpdater $updater): void
    {
        $saleUpdate = $item->saleProductUpdate;
        if (!$saleUpdate instanceof SaleProductUpdate) {
            $this->markItemSkipped($item, 'Sale update record no longer exists.');
            return;
        }

        if ($saleUpdate->status === SaleProductUpdate::STATUS_COMPLETED) {
            $this->markItemCompleted($item, ['message' => 'Already completed before this retry.']);
            return;
        }

        if (!in_array($saleUpdate->status, [
            SaleProductUpdate::STATUS_APPROVED,
            SaleProductUpdate::STATUS_SCHEDULED,
            SaleProductUpdate::STATUS_RUNNING,
        ], true)) {
            $this->markItemSkipped($item, 'Sale update is no longer approved for scheduling.');
            return;
        }

        $item->update([
            'status' => ScheduledJobItem::STATUS_RUNNING,
            'started_at' => $item->started_at ?? now(),
            'error_message' => null,
        ]);
        $saleUpdate->update([
            'status' => SaleProductUpdate::STATUS_RUNNING,
            'error_message' => null,
        ]);

        try {
            $response = $updater->syncSaleProductUpdate($saleUpdate);
            $this->markLocalSaleValuesSynced($saleUpdate);

            $saleUpdate->update([
                'status' => SaleProductUpdate::STATUS_COMPLETED,
                'pushed_at' => now(),
                'error_message' => null,
            ]);
            $this->markItemCompleted($item, $response);
        } catch (\Throwable $e) {
            $message = $e->getMessage();
            $saleUpdate->update([
                'status' => SaleProductUpdate::STATUS_FAILED,
                'error_message' => $message,
            ]);
            $item->update([
                'status' => ScheduledJobItem::STATUS_FAILED,
                'error_message' => $message,
                'completed_at' => now(),
            ]);

            logger()->error('Scheduled sale product update failed', [
                'scheduled_job_item_id' => $item->id,
                'sale_product_update_id' => $saleUpdate->id,
                'product_id' => $saleUpdate->product_id,
                'sku' => $saleUpdate->sku,
                'error' => $message,
            ]);
        }
    }

    private function markLocalSaleValuesSynced(SaleProductUpdate $saleUpdate): void
    {
        $product = $saleUpdate->product;
        if ($product instanceof Product) {
            Product::withoutEvents(function () use ($product, $saleUpdate): void {
                $product->forceFill([
                    'tags' => $saleUpdate->prepared_tags ?: $product->tags,
                    'last_synced_at' => now(),
                ])->save();
            });
        }

        $variant = $saleUpdate->variant;
        if ($variant instanceof Variant) {
            Variant::withoutEvents(function () use ($variant, $saleUpdate): void {
                $variant->forceFill([
                    'price' => $saleUpdate->sale_price,
                    'compare_at_price' => $saleUpdate->compare_at_price,
                    'sync_state' => Variant::SYNC_STATE_SYNCED,
                    'local_dirty' => false,
                    'last_synced_at' => now(),
                ])->save();
            });
        }
    }

    private function markItemCompleted(ScheduledJobItem $item, array $response): void
    {
        $item->update([
            'status' => ScheduledJobItem::STATUS_COMPLETED,
            'response' => $response,
            'error_message' => null,
            'completed_at' => now(),
        ]);
    }

    private function markItemSkipped(ScheduledJobItem $item, string $message): void
    {
        $item->update([
            'status' => ScheduledJobItem::STATUS_SKIPPED,
            'error_message' => $message,
            'completed_at' => now(),
        ]);
    }
}
