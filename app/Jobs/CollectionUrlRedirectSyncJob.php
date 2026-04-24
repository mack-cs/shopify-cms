<?php

namespace App\Jobs;

use App\Models\CollectionUrlRedirect;
use App\Services\AdminNotification;
use App\Services\CollectionUrlRedirectService;
use Filament\Notifications\Notification;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

class CollectionUrlRedirectSyncJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /**
     * @param array<int, int> $redirectIds
     */
    public function __construct(
        public array $redirectIds,
        public ?int $userId = null,
    ) {}

    public function handle(CollectionUrlRedirectService $service): void
    {
        $redirects = CollectionUrlRedirect::query()
            ->whereIn('id', $this->redirectIds)
            ->get();

        $result = $service->syncRedirects($redirects);

        if (!$this->userId) {
            return;
        }

        $parts = [];
        if ($result['synced'] > 0) {
            $parts[] = "Synced {$result['synced']}.";
        }
        if ($result['failed'] > 0) {
            $parts[] = "Failed {$result['failed']}.";
        }
        if ($result['skipped'] > 0) {
            $parts[] = "Skipped {$result['skipped']}.";
        }
        if (!empty($result['errors'])) {
            $parts[] = collect($result['errors'])->take(5)->implode(' | ');
        }

        $notification = Notification::make()
            ->title('Collection URL redirect sync complete')
            ->body($parts ? implode(' ', $parts) : 'No redirects were processed.');

        if ($result['failed'] > 0) {
            $notification->danger();
        } elseif ($result['synced'] > 0) {
            $notification->success();
        } else {
            $notification->warning();
        }

        AdminNotification::sendToUserId($notification, $this->userId);
    }
}
