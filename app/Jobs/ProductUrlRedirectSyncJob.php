<?php

namespace App\Jobs;

use App\Models\ProductUrlRedirect;
use App\Models\User;
use App\Services\ProductUrlRedirectService;
use Filament\Notifications\Notification;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

class ProductUrlRedirectSyncJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /**
     * @param array<int, int> $redirectIds
     */
    public function __construct(
        public array $redirectIds,
        public ?int $userId = null,
    ) {}

    public function handle(ProductUrlRedirectService $service): void
    {
        $redirects = ProductUrlRedirect::query()
            ->whereIn('id', $this->redirectIds)
            ->get();

        $result = $service->syncRedirects($redirects);

        if (!$this->userId) {
            return;
        }

        $user = User::find($this->userId);
        if (!$user) {
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
            ->title('Shopify URL redirect sync complete')
            ->body($parts ? implode(' ', $parts) : 'No redirects were processed.');

        if ($result['failed'] > 0) {
            $notification->danger();
        } elseif ($result['synced'] > 0) {
            $notification->success();
        } else {
            $notification->warning();
        }

        $notification->sendToDatabase($user);
    }
}
