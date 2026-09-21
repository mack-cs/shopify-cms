<?php

namespace App\Jobs\Shopify;

use App\Models\ShopifySyncRun;
use App\Services\Shopify\ShopifyBulkOperationService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\Middleware\WithoutOverlapping;
use Illuminate\Queue\SerializesModels;

class PollShopifyInventoryBulkExport implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 5;
    public array $backoff = [60, 120, 300, 600];
    public int $timeout = 120;

    public function __construct(public int $syncRunId)
    {
    }

    public function middleware(): array
    {
        return [(new WithoutOverlapping('shopify-bulk-inventory-poll-' . $this->syncRunId))->dontRelease()];
    }

    public function handle(ShopifyBulkOperationService $bulk): void
    {
        $run = ShopifySyncRun::query()->findOrFail($this->syncRunId);

        try {
            $run->increment('poll_attempts');
            if ($run->poll_attempts > (int) config('shopify_sync.inventory.max_poll_attempts', 100)) {
                throw new \RuntimeException('Shopify inventory bulk operation exceeded the maximum poll attempts.');
            }

            $operation = $bulk->poll($run->fresh());
            $status = (string) ($operation['status'] ?? '');

            if ($status === ShopifyBulkOperationService::STATUS_COMPLETED) {
                DownloadShopifyInventoryBulkResult::dispatch($run->id, (string) ($operation['url'] ?? ''));
                return;
            }

            if ($bulk->isTerminal($status)) {
                throw new \RuntimeException("Shopify inventory bulk operation ended with status {$status}.");
            }

            self::dispatch($run->id)
                ->delay(now()->addSeconds((int) config('shopify_sync.inventory.poll_delay_seconds', 120)));
        } catch (\Throwable $exception) {
            $run->fresh()?->fail($exception->getMessage());
            throw $exception;
        }
    }
}
