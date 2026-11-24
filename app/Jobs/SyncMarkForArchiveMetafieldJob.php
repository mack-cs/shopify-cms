<?php

namespace App\Jobs;

use App\Models\Product;
use App\Services\Procurement\ProcurementArchiveFlagSyncService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

final class SyncMarkForArchiveMetafieldJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 5;

    public int $timeout = 120;

    /** @return array<int,int> */
    public function backoff(): array
    {
        return [10, 30, 60, 120];
    }

    public function __construct(
        public readonly int $productId,
        public readonly int $expectedVersion,
    ) {}

    public function handle(ProcurementArchiveFlagSyncService $sync): void
    {
        $product = Product::query()->find($this->productId);
        if (! $product instanceof Product) {
            Log::warning('Mark-for-archive sync skipped because the product disappeared.', [
                'product_id' => $this->productId,
                'expected_version' => $this->expectedVersion,
            ]);

            return;
        }

        $result = $sync->syncProduct($product, $this->expectedVersion);
        if ($result['status'] === ProcurementArchiveFlagSyncService::STATUS_FAILED && ! $result['skipped']) {
            throw new \RuntimeException(
                $result['error'] !== null && $result['error'] !== ''
                    ? $result['error']
                    : 'Mark-for-archive metafield sync failed.'
            );
        }
    }
}
