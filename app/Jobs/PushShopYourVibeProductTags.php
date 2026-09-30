<?php

namespace App\Jobs;

use App\Services\AdminNotification;
use App\Services\ShopYourVibeTagService;
use Filament\Notifications\Notification;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Throwable;

class PushShopYourVibeProductTags implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $timeout = 600;

    /**
     * @param array<string, mixed> $state
     */
    public function __construct(
        private readonly string $productGid,
        private readonly array $state,
        private readonly ?int $userId = null,
    ) {
        $this->onConnection('shop-your-vibe');
        $this->onQueue('shop-your-vibe');
    }

    public function handle(ShopYourVibeTagService $service): void
    {
        try {
            $service->save($this->productGid, $this->state, $this->userId);
        } catch (Throwable $exception) {
            $this->notify(
                'Product metafields failed',
                $exception->getMessage(),
                false
            );

            throw $exception;
        }

        $this->notify(
            'Product metafields saved to Shopify',
            'The Shop Your Vibe product metafields were updated.',
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
