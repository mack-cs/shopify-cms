<?php

namespace App\Jobs;

use App\Services\AdminNotification;
use App\Services\ShopYourVibeComplementaryService;
use Filament\Notifications\Notification;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Throwable;

class PushShopYourVibeComplementaryProducts implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $timeout = 600;

    public function __construct(
        private readonly string $productGid,
        private readonly ?int $userId = null,
    ) {
        $this->onConnection('shop-your-vibe');
        $this->onQueue('shop-your-vibe');
    }

    public function handle(ShopYourVibeComplementaryService $service): void
    {
        try {
            $result = $service->pushToShopify($this->productGid, $this->userId);
        } catch (Throwable $exception) {
            $this->notify('Complementary products failed', $exception->getMessage(), false);

            throw $exception;
        }

        $failures = $result['failures'] ?? [];
        if ($failures !== []) {
            $this->notify('Complementary products failed', data_get($failures, '0.details', 'Shopify did not accept the update.'), false);

            return;
        }

        $this->notify(
            'Complementary products queued to Shopify',
            'Shopify was updated with the first three sellable complementary products.',
            true
        );
    }

    private function notify(string $title, string $body, bool $success): void
    {
        if (! $this->userId) {
            return;
        }

        $notification = Notification::make()
            ->title($title)
            ->body($body);

        $notification = $success ? $notification->success() : $notification->danger();
        AdminNotification::sendToUserId($notification, $this->userId);
    }
}
