<?php

namespace App\Jobs;

use App\Models\Import;
use App\Models\Product;
use App\Models\Setting;
use App\Models\User;
use App\Services\ShopifyApiImporter;
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
            $importer->importIntoExistingImport($import);
            $snapshotService->generateForImport($import->fresh());
            $draftSeeder->seedMissingFromProducts($import->id, $import->created_by);
            $draftSync->syncApprovedDrafts();
            Setting::putValue('shopify_last_sync_at', now()->toISOString());

            Product::query()
                ->where('import_id', $import->id)
                ->pluck('id')
                ->chunk(100)
                ->each(function ($chunk) use ($import): void {
                    ProductImageBackupJob::dispatch(
                        $chunk->map(fn ($id): int => (int) $id)->all(),
                        $import->created_by,
                        'Post-import image backup'
                    );
                });

            $user = User::find($import->created_by);
            if ($user) {
                Notification::make()
                    ->title('Shopify sync complete')
                    ->body("Import #{$import->id} is ready. Image backup is queued and the Shopify snapshot CSV is ready.")
                    ->success()
                    ->sendToDatabase($user);
            }
        } catch (\Throwable $e) {
            $import->update(['status' => 'failed']);
            $user = User::find($import->created_by);
            if ($user) {
                Notification::make()
                    ->title('Shopify sync failed')
                    ->body($e->getMessage())
                    ->danger()
                    ->sendToDatabase($user);
            }
            throw $e;
        }
    }
}
