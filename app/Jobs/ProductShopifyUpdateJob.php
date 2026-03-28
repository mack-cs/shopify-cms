<?php

namespace App\Jobs;

use App\Models\Product;
use App\Services\ProductShopifyUpdater;
use Filament\Notifications\Notification;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

class ProductShopifyUpdateJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /**
     * @param array<int, int> $productIds
     */
    public function __construct(
        public array $productIds,
        public ?int $userId = null,
    ) {}

    public function handle(ProductShopifyUpdater $updater): void
    {
        $products = Product::query()
            ->whereIn('id', $this->productIds)
            ->get();

        $result = $updater->updateApprovedProducts($products);

        $updatedProductIds = array_values(array_unique(array_map('intval', $result['updated_product_ids'] ?? [])));
        foreach (array_chunk($updatedProductIds, 100) as $chunk) {
            ProductImageBackupJob::dispatch($chunk, $this->userId, 'Post-product sync image backup');
        }

        if ($this->userId) {
            $parts = [];
            if ($result['updated'] > 0) {
                $parts[] = "Updated {$result['updated']}.";
                if (!empty($updatedProductIds)) {
                    $parts[] = 'Image backup queued.';
                }
            }
            if ($result['skipped_not_approved'] > 0) {
                $parts[] = "Skipped {$result['skipped_not_approved']} not approved.";
            }
            if ($result['skipped_missing_handle'] > 0) {
                $parts[] = "Skipped {$result['skipped_missing_handle']} missing handle.";
            }
            if ($result['failed'] > 0) {
                $parts[] = "Failed {$result['failed']}.";
            }

            if (!empty($result['warnings'])) {
                $warnings = collect($result['warnings'])
                    ->take(5)
                    ->map(fn (array $warning) => "ID {$warning['product_id']}: {$warning['warning']}")
                    ->implode(' | ');
                $parts[] = "Warnings: {$warnings}";
            }

            if (!empty($result['failures'])) {
                $failures = collect($result['failures'])
                    ->take(5)
                    ->map(fn (array $failure) => "ID {$failure['product_id']}: {$failure['details']}")
                    ->implode(' | ');
                $parts[] = "Failures: {$failures}";
            }

            Notification::make()
                ->title('Shopify product update complete')
                ->body($parts ? implode(' ', $parts) : 'No products were updated.')
                ->success()
                ->sendToDatabase(\App\Models\User::find($this->userId));
        }
    }
}
