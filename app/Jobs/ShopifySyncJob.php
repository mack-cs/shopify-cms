<?php

namespace App\Jobs;

use App\Models\Import;
use App\Models\Setting;
use App\Services\AdminNotification;
use App\Services\ShopifyApiImporter;
use App\Services\ShopifyMissingDraftWorkflowService;
use App\Services\ShopifyMissingProductDetector;
use App\Services\NewProductDraftProductSync;
use App\Services\NewProductDraftSeeder;
use App\Services\ShopifySyncSnapshotService;
use Filament\Notifications\Notification;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

class ShopifySyncJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $timeout = 1800;

    public function __construct(private readonly int $importId)
    {
    }

    public function handle(
        ShopifyApiImporter $importer,
        ShopifyMissingDraftWorkflowService $missingDraftWorkflow,
        ShopifyMissingProductDetector $missingProductDetector,
        NewProductDraftProductSync $draftSync,
        NewProductDraftSeeder $draftSeeder,
        ShopifySyncSnapshotService $snapshotService
    ): void
    {
        set_time_limit(0);

        $import = Import::find($this->importId);
        if (!$import) {
            return;
        }

        $import->update(['status' => 'processing']);

        try {
            $previousImport = Import::query()
                ->where('id', '!=', $import->id)
                ->where('filename', 'shopify-api')
                ->orderByDesc('id')
                ->first();

            $importer->importIntoExistingImport($import);
            $missingCount = $missingProductDetector->detect($import->fresh(), $previousImport);
            $blockedDraftCount = $missingDraftWorkflow->flagFromMissingProducts($import->id);
            $snapshotService->generateForImport($import->fresh());
            $draftSeeder->seedMissingFromProducts($import->id, $import->created_by);
            $draftSync->syncApprovedDrafts();
            Setting::putValue('shopify_last_sync_at', now()->toISOString());

            AdminNotification::sendToUserId(
                Notification::make()
                    ->title('Shopify sync complete')
                    ->body(
                        "Import #{$import->id} is ready. Shopify image changes were queued for backup and the Shopify snapshot CSV is ready." .
                        ($missingCount > 0 ? " {$missingCount} product(s) were missing from the latest Shopify sync. See Audit & History -> Shopify Missing Products." : '') .
                        ($blockedDraftCount > 0 ? " {$blockedDraftCount} draft recovery record(s) were blocked from automatic re-sync. Review them in New Products." : '')
                    )
                    ->success(),
                $import->created_by
            );
        } catch (\Throwable $e) {
            $import->update(['status' => 'failed']);
            AdminNotification::sendToUserId(
                Notification::make()
                    ->title('Shopify sync failed')
                    ->body($e->getMessage())
                    ->danger(),
                $import->created_by
            );
            throw $e;
        }
    }
}
