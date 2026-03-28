<?php

namespace App\Jobs;

use App\Models\Product;
use App\Models\User;
use App\Services\ProductImageBackupService;
use App\Services\ProductShopifyUpdater;
use Filament\Notifications\Notification;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

class ProductImageShopifySyncJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $timeout = 1800;

    /**
     * @param array<int, int> $productIds
     */
    public function __construct(
        public array $productIds,
        public ?int $userId = null,
        public ?string $reason = null,
    ) {}

    public function handle(
        ProductImageBackupService $backupService,
        ProductShopifyUpdater $updater,
    ): void {
        $products = Product::query()
            ->whereIn('id', $this->productIds)
            ->get();

        $backupResult = $backupService->backupProducts($products);
        $result = $updater->syncProductImages($products);

        if (!$this->userId) {
            return;
        }

        $user = User::find($this->userId);
        if (!$user) {
            return;
        }

        $parts = [];
        if ($this->reason) {
            $parts[] = $this->reason . '.';
        }
        $parts[] = "Products {$products->count()}.";
        $parts[] = "Images processed {$backupResult['processed']}.";
        if ($result['synced'] > 0) {
            $parts[] = "Shopify image sync {$result['synced']}.";
        }
        if ($result['skipped_not_approved'] > 0) {
            $parts[] = "Skipped not approved {$result['skipped_not_approved']}.";
        }
        if ($result['skipped_missing_handle'] > 0) {
            $parts[] = "Skipped missing handle {$result['skipped_missing_handle']}.";
        }
        if ($backupResult['backed_up'] > 0) {
            $parts[] = "Backed up {$backupResult['backed_up']}.";
        }
        if ($backupResult['reused'] > 0) {
            $parts[] = "Reused backups {$backupResult['reused']}.";
        }
        if ($backupResult['missing_source'] > 0) {
            $parts[] = "Missing source {$backupResult['missing_source']}.";
        }
        if ($result['failed'] > 0) {
            $parts[] = "Failed {$result['failed']}.";
        }

        if (!empty($result['warnings'])) {
            $warnings = collect($result['warnings'])
                ->take(3)
                ->map(fn (array $warning): string => "Product {$warning['product_id']}: {$warning['warning']}")
                ->implode(' | ');
            if ($warnings !== '') {
                $parts[] = "Warnings: {$warnings}";
            }
        }

        $notification = Notification::make()
            ->title('Shopify image sync complete')
            ->body(implode(' ', $parts));

        if ($result['failed'] > 0) {
            $notification->warning();
        } else {
            $notification->success();
        }

        $notification->sendToDatabase($user);
    }
}
