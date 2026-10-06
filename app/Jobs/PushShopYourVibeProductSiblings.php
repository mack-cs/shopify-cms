<?php

namespace App\Jobs;

use App\Services\AdminNotification;
use App\Services\ShopYourVibeSiblingService;
use Filament\Notifications\Notification;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Throwable;

class PushShopYourVibeProductSiblings implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $timeout = 600;

    /**
     * @param array<string, mixed> $parent
     * @param array<int, string> $selectedTags
     */
    public function __construct(
        private readonly array $parent,
        private readonly string $productGid,
        private readonly array $selectedTags,
        private readonly ?int $userId = null,
    ) {
        $this->onConnection('shop-your-vibe');
        $this->onQueue('shop-your-vibe');
    }

    public function handle(ShopYourVibeSiblingService $service): void
    {
        try {
            $service->assign($this->parent, $this->productGid, $this->selectedTags);
        } catch (Throwable $exception) {
            $this->notify('Sibling assignments failed', $exception->getMessage(), false);

            throw $exception;
        }

        $this->notify(
            'Sibling assignments saved to Shopify',
            'The Shop Your Vibe sibling tags were updated.',
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
