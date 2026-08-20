<?php

namespace App\Jobs;

use App\Models\ShopifyCollectionProductReportRun;
use App\Services\AdminNotification;
use App\Services\GoogleSheetsCollectionMappingPublisher;
use Filament\Notifications\Notification;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Throwable;

final class PublishCollectionMappingToGoogleSheetsJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $timeout = 600;

    public int $tries = 2;

    /** @param array<int, string> $collectionHandles */
    public function __construct(
        public readonly int $runId,
        public readonly array $collectionHandles,
        public readonly ?int $requestedBy = null,
    ) {}

    public function handle(GoogleSheetsCollectionMappingPublisher $publisher): void
    {
        $run = ShopifyCollectionProductReportRun::query()
            ->where('status', ShopifyCollectionProductReportRun::STATUS_COMPLETED)
            ->find($this->runId);
        if (! $run instanceof ShopifyCollectionProductReportRun) {
            return;
        }

        try {
            $published = $publisher->publish($run, $this->collectionHandles);

            AdminNotification::sendToUserId(
                Notification::make()
                    ->title('Google Sheets export ready')
                    ->body("Updated {$published} collection tab(s) from report run #{$run->id}.")
                    ->success(),
                $this->requestedBy,
            );
        } catch (Throwable $exception) {
            AdminNotification::sendToUserId(
                Notification::make()
                    ->title('Google Sheets export failed')
                    ->body($exception->getMessage())
                    ->danger(),
                $this->requestedBy,
            );

            throw $exception;
        }
    }
}
