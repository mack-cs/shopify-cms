<?php

namespace App\Jobs;

use App\Models\ShopifyCollectionProductReportRun;
use App\Services\AdminNotification;
use App\Services\ShopifyCollectionProductReportService;
use Filament\Notifications\Notification;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Throwable;

final class GenerateShopifyCollectionProductReportJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $timeout = 3600;

    public int $tries = 2;

    public function __construct(public readonly int $runId) {}

    public function handle(ShopifyCollectionProductReportService $reports): void
    {
        $run = ShopifyCollectionProductReportRun::query()->find($this->runId);
        if (! $run instanceof ShopifyCollectionProductReportRun) {
            return;
        }

        try {
            $run = $reports->generate($run);
            AdminNotification::sendToUserId(
                Notification::make()
                    ->title('Collection product mapping ready')
                    ->body("Mapped {$run->relationship_count} collection/product relationships across {$run->collection_count} collections.")
                    ->success(),
                $run->requested_by,
            );
        } catch (Throwable $exception) {
            $run->forceFill([
                'status' => ShopifyCollectionProductReportRun::STATUS_FAILED,
                'failure_message' => $exception->getMessage(),
                'completed_at' => now(),
            ])->save();

            AdminNotification::sendToUserId(
                Notification::make()
                    ->title('Collection product mapping failed')
                    ->body($exception->getMessage())
                    ->danger(),
                $run->requested_by,
            );

            throw $exception;
        }
    }
}
