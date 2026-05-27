<?php

namespace App\Jobs;

use App\Services\AdminNotification;
use App\Services\AsyncJobStateService;
use App\Services\ComplementaryProductReconciliationService;
use Filament\Notifications\Notification;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

class ReconcileComplementaryProductsJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $timeout = 1800;

    public function __construct(
        public ?int $userId = null,
    ) {
    }

    public function handle(ComplementaryProductReconciliationService $service): void
    {
        try {
            $result = $service->run($this->userId);

            if (!$this->userId) {
                return;
            }

            $parts = [
                'Products checked: ' . (int) ($result['products_checked'] ?? 0) . '.',
                'Variants refreshed: ' . (int) ($result['variants_refreshed'] ?? 0) . '.',
                'Sellability changed: ' . (int) ($result['sellability_changed'] ?? 0) . '.',
                'Complementary synced: ' . (int) ($result['complementary_synced'] ?? 0) . '.',
            ];

            if (($result['shortage_count'] ?? 0) > 0) {
                $parts[] = 'Shortages needing attention: ' . (int) $result['shortage_count'] . '.';
            }

            if (!empty($result['warnings'])) {
                $parts[] = 'Warnings: ' . implode(' | ', array_slice($result['warnings'], 0, 3));
            }

            if (!empty($result['failures'])) {
                $parts[] = 'Errors: ' . implode(' | ', array_slice($result['failures'], 0, 2));
            }

            $notification = Notification::make()
                ->title('Complementary reconciliation complete')
                ->body(implode(' ', $parts));

            if (!empty($result['failures'])) {
                $notification->warning();
            } elseif (($result['shortage_count'] ?? 0) > 0) {
                $notification->warning();
            } else {
                $notification->success();
            }

            AdminNotification::sendToUserId($notification, $this->userId);
        } finally {
            app(AsyncJobStateService::class)->markFinished(AsyncJobStateService::COMPLEMENTARY_RECONCILIATION);
        }
    }
}
