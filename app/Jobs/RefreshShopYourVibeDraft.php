<?php

namespace App\Jobs;

use App\Models\ShopYourVibeDraft;
use App\Services\AdminNotification;
use App\Services\ShopYourVibeWorkflow;
use Filament\Notifications\Notification;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Throwable;

class RefreshShopYourVibeDraft implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 1;

    public int $timeout = 840;

    public function __construct(
        private readonly int $draftId,
        private readonly int $revision,
        private readonly bool $discard,
        private readonly ?int $userId = null,
    ) {
        $this->onConnection('shop-your-vibe');
        $this->onQueue('shop-your-vibe');
    }

    public function handle(ShopYourVibeWorkflow $workflow): void
    {
        try {
            $revision = ShopYourVibeDraft::whereKey($this->draftId)->value('revision');
            if ($revision === null) {
                throw new \RuntimeException('This Shop Your Vibe draft no longer exists.');
            }

            $workflow->refresh($this->draftId, (int) $revision, $this->discard);
        } catch (Throwable $exception) {
            $this->notify(
                'Shop Your Vibe refresh failed',
                $exception->getMessage(),
                false
            );

            throw $exception;
        }

        $this->notify(
            'Shop Your Vibe refreshed',
            'Latest Shopify state has been loaded.',
            true
        );
    }

    private function notify(string $title, string $body, bool $success): void
    {
        if (!$this->userId) {
            return;
        }

        $notification = Notification::make()
            ->title($title)
            ->body($body);

        $notification = $success ? $notification->success() : $notification->danger();
        AdminNotification::sendToUserId($notification, $this->userId);
    }
}
