<?php

namespace App\Jobs\Shopify;

use App\Models\ShopifySyncRun;
use App\Services\Shopify\ShopifyBulkOperationService;
use App\Services\Shopify\ShopifyOrderQueryBuilder;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\Middleware\WithoutOverlapping;
use Illuminate\Queue\SerializesModels;

class StartShopifyOrdersBulkExport implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 5;
    public array $backoff = [60, 120, 300, 600];
    public int $timeout = 180;

    public function __construct(public int $syncRunId)
    {
    }

    public function middleware(): array
    {
        return [
            (new WithoutOverlapping('shopify-bulk-orders'))->dontRelease(),
            (new WithoutOverlapping('shopify-orders-run-' . $this->syncRunId))->dontRelease(),
        ];
    }

    public function handle(ShopifyOrderQueryBuilder $queries, ShopifyBulkOperationService $bulk): void
    {
        $run = ShopifySyncRun::query()->findOrFail($this->syncRunId);

        try {
            $run->forceFill([
                'status' => ShopifySyncRun::STATUS_STARTING,
                'started_at' => $run->started_at ?? now(),
            ])->save();

            $query = $run->sync_type === ShopifySyncRun::SYNC_TYPE_FULL
                ? $queries->full()
                : $queries->updatedBetween($run->window_start, $run->window_end);

            $bulk->start($run, $query);

            PollShopifyOrdersBulkExport::dispatch($run->id)
                ->delay(now()->addSeconds((int) config('shopify_sync.orders.first_poll_delay_seconds', 60)));
        } catch (\Throwable $exception) {
            $run->fail($exception->getMessage());
            throw $exception;
        }
    }
}
