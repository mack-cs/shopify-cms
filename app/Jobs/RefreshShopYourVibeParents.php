<?php

namespace App\Jobs;

use App\Services\AdminNotification;
use App\Services\ShopYourVibeShopify;
use Filament\Notifications\Notification;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Cache;
use Throwable;

class RefreshShopYourVibeParents implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 1;

    public int $timeout = 840;

    public function __construct(
        private readonly string $cacheKey,
        private readonly ?int $userId = null,
    ) {
        $this->onConnection('shop-your-vibe');
        $this->onQueue('shop-your-vibe');
    }

    public function handle(ShopYourVibeShopify $shopify): void
    {
        try {
            Cache::put($this->cacheKey, $shopify->parents(), 300);
        } catch (Throwable $exception) {
            $this->notify(
                'Shop Your Vibe collection refresh failed',
                $exception->getMessage(),
                false
            );

            throw $exception;
        }

        $this->notify(
            'Shop Your Vibe collections refreshed',
            'Latest Shopify collections are ready. Reload the page to view them.',
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
