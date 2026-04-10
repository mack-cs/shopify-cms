<?php

namespace App\Jobs;

use App\Models\Image;
use App\Models\Product;
use App\Services\AdminNotification;
use App\Services\ProductImageBackupService;
use App\Services\ProductShopifyUpdater;
use Filament\Notifications\Notification;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

class SelectedProductImageShopifySyncJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $timeout = 1800;

    /**
     * @param array<int, int> $imageIds
     */
    public function __construct(
        public int $productId,
        public array $imageIds,
        public ?int $userId = null,
        public ?string $reason = null,
    ) {}

    public function handle(
        ProductImageBackupService $backupService,
        ProductShopifyUpdater $updater,
    ): void {
        $product = Product::query()->find($this->productId);
        if (!$product) {
            return;
        }

        $imageIds = array_values(array_unique(array_map('intval', $this->imageIds)));
        $images = Image::query()
            ->where('product_id', $product->id)
            ->whereIn('id', $imageIds)
            ->get();

        $backupResult = $backupService->backupImages($images);
        $result = $updater->syncSelectedProductImages($product->fresh(), $imageIds);

        if (!$this->userId) {
            return;
        }

        $parts = [];
        if ($this->reason) {
            $parts[] = $this->reason . '.';
        }
        $parts[] = "Selected images {$images->count()}.";
        $parts[] = "Backups processed {$backupResult['processed']}.";

        if ($backupResult['backed_up'] > 0) {
            $parts[] = "Backed up {$backupResult['backed_up']}.";
        }
        if ($backupResult['reused'] > 0) {
            $parts[] = "Reused backups {$backupResult['reused']}.";
        }
        if ($backupResult['missing_source'] > 0) {
            $parts[] = "Missing source {$backupResult['missing_source']}.";
        }
        if ($result['synced'] > 0) {
            $parts[] = 'Shopify selected image sync complete.';
        }
        if ($result['skipped_not_approved'] > 0) {
            $parts[] = 'Skipped because product is not approved.';
        }
        if ($result['skipped_missing_handle'] > 0) {
            $parts[] = 'Skipped because product handle is missing.';
        }
        if ($result['failed'] > 0) {
            $parts[] = "Failed {$result['failed']}.";
        }

        if (!empty($result['warnings'])) {
            $warnings = collect($result['warnings'])
                ->take(4)
                ->map(fn (array $warning): string => $warning['warning'])
                ->implode(' | ');
            if ($warnings !== '') {
                $parts[] = "Warnings: {$warnings}";
            }
        }

        $notification = Notification::make()
            ->title('Selected image sync complete')
            ->body(implode(' ', $parts));

        if ($result['failed'] > 0 || $backupResult['failed'] > 0) {
            $notification->warning();
        } else {
            $notification->success();
        }

        AdminNotification::sendToUserId($notification, $this->userId);
    }
}
