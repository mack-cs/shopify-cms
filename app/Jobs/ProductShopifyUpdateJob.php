<?php

namespace App\Jobs;

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
use Illuminate\Support\Str;

class ProductShopifyUpdateJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /**
     * @param array<int, int> $productIds
     */
    public function __construct(
        public array $productIds,
        public ?int $userId = null,
        public ?array $scopes = null,
        public ?array $coreFields = null,
        public ?string $syncBatchId = null,
    ) {}

    public function handle(ProductShopifyUpdater $updater, ProductImageBackupService $backupService): void
    {
        $products = Product::query()
            ->whereIn('id', $this->productIds)
            ->get();

        $syncBatchId = $this->syncBatchId ?: (string) Str::uuid();
        $backupResult = null;
        if ($this->shouldPrepareImageBackups()) {
            $backupResult = $backupService->backupProducts($products);
        }

        $result = $updater->updateApprovedProducts($products, $this->scopes, $this->coreFields, $syncBatchId, $this->userId);

        if ($this->userId) {
            $parts = [];
            $scopeLabels = ProductShopifyUpdater::syncScopeLabels();
            $coreFieldLabels = ProductShopifyUpdater::coreFieldLabels();
            if ($this->scopes === null) {
                $parts[] = 'Scopes: Automatic by approval state. Fully approved products run full sync; partially approved active products sync only their approved fields.';
            } else {
                $scopeSummary = collect($this->scopes)
                    ->map(fn (string $scope): string => $scopeLabels[$scope] ?? $scope)
                    ->implode(', ');

                $parts[] = "Scopes: {$scopeSummary}.";
            }

            if ($this->scopes !== null && in_array(ProductShopifyUpdater::SYNC_SCOPE_PRODUCT, $this->scopes, true)) {
                $coreSummary = empty($this->coreFields)
                    ? 'none selected'
                    : collect($this->coreFields)
                        ->map(fn (string $field): string => $coreFieldLabels[$field] ?? $field)
                        ->implode(', ');
                $parts[] = "Core fields: {$coreSummary}.";
            }
            if ($result['updated'] > 0) {
                $parts[] = "Updated {$result['updated']}.";
                $parts[] = 'Sync batch ' . substr(str_replace('-', '', $syncBatchId), 0, 8) . '.';
            }
            if ($backupResult !== null) {
                $parts[] = "Image backups processed {$backupResult['processed']}.";
                if ($backupResult['backed_up'] > 0) {
                    $parts[] = "Backed up {$backupResult['backed_up']}.";
                }
                if ($backupResult['reused'] > 0) {
                    $parts[] = "Reused backups {$backupResult['reused']}.";
                }
                if ($backupResult['missing_source'] > 0) {
                    $parts[] = "Missing image source {$backupResult['missing_source']}.";
                }
                if ($backupResult['failed'] > 0) {
                    $parts[] = "Image backup failures {$backupResult['failed']}.";
                }
            }
            if ($result['skipped_not_approved'] > 0) {
                $parts[] = "Skipped {$result['skipped_not_approved']} not approved.";
            }
            if ($result['skipped_missing_handle'] > 0) {
                $parts[] = "Skipped {$result['skipped_missing_handle']} missing handle.";
            }
            if (($result['skipped_blocked'] ?? 0) > 0) {
                $parts[] = "Skipped {$result['skipped_blocked']} blocked because Shopify removed them and recovery is not enabled.";
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

            AdminNotification::sendToUserId(
                Notification::make()
                    ->title('Shopify product update complete')
                    ->body($parts ? implode(' ', $parts) : 'No products were updated.')
                    ->status($result['failed'] > 0 ? 'danger' : 'success'),
                $this->userId
            );
        }
    }

    private function shouldPrepareImageBackups(): bool
    {
        return $this->scopes === null
            || in_array(ProductShopifyUpdater::SYNC_SCOPE_IMAGES, $this->scopes, true);
    }
}
