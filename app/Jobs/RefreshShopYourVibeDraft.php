<?php

namespace App\Jobs;

use App\Models\ShopYourVibeDraft;
use App\Services\AdminNotification;
use App\Services\ShopYourVibeWorkflow;
use Filament\Notifications\Notification;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Cache;
use Throwable;

class RefreshShopYourVibeDraft implements ShouldBeUnique, ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 1;

    public int $timeout = 840;

    public int $uniqueFor = 960;

    public function __construct(
        private readonly int $draftId,
        private readonly int $revision,
        private readonly bool $discard,
        private readonly ?int $userId = null,
    ) {
        $this->onConnection('shop-your-vibe');
        $this->onQueue('shop-your-vibe');
    }

    public function uniqueId(): string
    {
        return 'syv-refresh:'.$this->draftId;
    }

    public function handle(ShopYourVibeWorkflow $workflow): void
    {
        $draft = ShopYourVibeDraft::find($this->draftId);
        if (! $draft) {
            throw new \RuntimeException('This Shop Your Vibe draft no longer exists.');
        }
        if ($draft->pending || (int) $draft->revision !== $this->revision) {
            Cache::put(ShopYourVibeWorkflow::progressKey($draft->collection_gid), [
                'state' => 'skipped',
                'done' => null,
                'total' => null,
                'message' => 'Refresh skipped. Newer local changes were kept.',
            ], 120);
            $this->notify(
                'Shop Your Vibe refresh skipped',
                'This collection changed after the refresh was requested. Local edits were kept.',
                false
            );

            return;
        }

        $workflow->trackProductProgress(function (int $done, int $total, string $gid = '', string $title = '') use ($draft): void {
            $reportedTotal = $total > 0 ? $total : null;
            Cache::put(ShopYourVibeWorkflow::progressKey($draft->collection_gid), [
                'state' => 'running',
                'done' => $done,
                'total' => $reportedTotal,
                'percent' => $reportedTotal ? (int) floor(($done / $reportedTotal) * 100) : null,
                'stage_gid' => $gid,
                'message' => ShopYourVibeWorkflow::productProgressMessage($draft->collection_gid, $gid, $done, $total, $title),
            ], 600);
        });

        try {
            $workflow->refresh($this->draftId, $this->revision, $this->discard);
            Cache::put(ShopYourVibeWorkflow::progressKey($draft->collection_gid), [
                'state' => 'success',
                'done' => null,
                'total' => null,
                'message' => ShopYourVibeWorkflow::HYDRATION_SUCCESS_MESSAGE,
            ], 120);
        } catch (Throwable $exception) {
            $current = ShopYourVibeDraft::find($this->draftId);
            if ($current && ($current->pending || (int) $current->revision !== $this->revision)) {
                Cache::put(ShopYourVibeWorkflow::progressKey($draft->collection_gid), [
                    'state' => 'skipped',
                    'done' => null,
                    'total' => null,
                    'message' => 'Refresh skipped. Newer local changes were kept.',
                ], 120);
                $this->notify(
                    'Shop Your Vibe refresh skipped',
                    'This collection changed after the refresh was requested. Local edits were kept.',
                    false
                );

                return;
            }
            Cache::put(ShopYourVibeWorkflow::progressKey($draft->collection_gid), [
                'state' => 'failed',
                'done' => null,
                'total' => null,
                'message' => 'Some changes could not be synchronized. Retry is available.',
            ], 300);
            $this->notify(
                'Shop Your Vibe refresh failed',
                $exception->getMessage(),
                false
            );

            throw $exception;
        } finally {
            $workflow->trackProductProgress(null);
        }

        $this->notify(
            'Shop Your Vibe refreshed',
            'Latest Shopify state has been loaded.',
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
