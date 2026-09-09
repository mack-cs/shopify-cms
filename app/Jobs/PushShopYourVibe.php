<?php

namespace App\Jobs;

use App\Models\ShopYourVibeDraft;
use App\Services\ShopYourVibeWorkflow;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Throwable;

class PushShopYourVibe implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public int $timeout = 840;

    public function __construct(public readonly int $draftId)
    {
        $this->onConnection('shop-your-vibe');
        $this->onQueue('shop-your-vibe');
    }

    public function handle(ShopYourVibeWorkflow $workflow): void
    {
        $workflow->push($this->draftId);
    }

    public function failed(?Throwable $exception): void
    {
        ShopYourVibeDraft::whereKey($this->draftId)->where('status', 'pushing')->update([
            'status' => 'failed', 'pending' => true,
            'last_error' => 'Push interrupted. Some changes remain pending. Retry to confirm completed Shopify operations.',
        ]);
    }
}
