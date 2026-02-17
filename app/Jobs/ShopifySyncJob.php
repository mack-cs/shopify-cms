<?php

namespace App\Jobs;

use App\Models\Import;
use App\Models\User;
use App\Services\ShopifyApiImporter;
use App\Services\NewProductDraftProductSync;
use App\Services\NewProductDraftSeeder;
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
        NewProductDraftSeeder $draftSeeder
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
            $draftSeeder->seedMissingFromProducts($import->id, $import->created_by);
            $draftSync->syncApprovedDrafts();
            $user = User::find($import->created_by);
            if ($user) {
                Notification::make()
                    ->title('Shopify sync complete')
                    ->body("Import #{$import->id} is ready")
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
