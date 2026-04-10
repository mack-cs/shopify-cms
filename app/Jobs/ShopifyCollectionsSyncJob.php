<?php

namespace App\Jobs;

use App\Models\Import;
use App\Services\AdminNotification;
use App\Services\ShopifyCollectionsImporter;
use Filament\Notifications\Notification;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

class ShopifyCollectionsSyncJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $timeout = 1800;

    public function __construct(private readonly int $importId)
    {
    }

    public function handle(ShopifyCollectionsImporter $importer): void
    {
        set_time_limit(0);

        $import = Import::find($this->importId);
        if (!$import) {
            return;
        }

        $import->update(['status' => 'processing']);

        try {
            $importer->importIntoExistingImport($import);
            AdminNotification::sendToUserId(
                Notification::make()
                    ->title('Collections sync complete')
                    ->body("Import #{$import->id} is ready")
                    ->success(),
                $import->created_by
            );
        } catch (\Throwable $e) {
            $import->update(['status' => 'failed']);
            AdminNotification::sendToUserId(
                Notification::make()
                    ->title('Collections sync failed')
                    ->body($e->getMessage())
                    ->danger(),
                $import->created_by
            );
            throw $e;
        }
    }
}
