<?php

namespace App\Jobs;

use App\Models\ShopYourVibeDraft;
use App\Services\AdminNotification;
use App\Services\ShopYourVibeWorkflow;
use Filament\Notifications\Notification;
use Illuminate\Bus\Queueable;
use Illuminate\Bus\UniqueLock;
use Illuminate\Contracts\Cache\Repository as CacheRepository;
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

    public static function releaseUniqueLock(int $draftId): void
    {
        (new UniqueLock(app(CacheRepository::class)))->release(new self($draftId, 0, false));
    }

    public function handle(ShopYourVibeWorkflow $workflow): void
    {
        $draft = ShopYourVibeDraft::find($this->draftId);
        if (! $draft) {
            throw new \RuntimeException('This Shop Your Vibe draft no longer exists.');
        }
        if (($draft->pending && ! $this->discard) || (int) $draft->revision !== $this->revision) {
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

        try {
            $workflow->trackProductProgress(function (int $done, int $total, string $gid = '', string $title = '') use ($draft): void {
                $payload = ShopYourVibeWorkflow::productProgressPayload($draft->collection_gid, $gid, $done, $total, $title);
                Cache::put(ShopYourVibeWorkflow::progressKey($draft->collection_gid), [
                    'state' => 'running',
                    'kind' => 'refresh',
                    'stage' => $payload['stage'],
                    'done' => $payload['done'],
                    'total' => $payload['total'],
                    'percent' => $payload['percent'],
                    'stage_gid' => $gid,
                    'message' => $payload['message'],
                ], 600);
            });
            $workflow->refresh($this->draftId, $this->revision, $this->discard);
            Cache::put(ShopYourVibeWorkflow::progressKey($draft->collection_gid), [
                'state' => 'success',
                'kind' => 'refresh',
                'done' => null,
                'total' => null,
                'message' => ShopYourVibeWorkflow::HYDRATION_SUCCESS_MESSAGE,
            ], 120);
        } catch (Throwable $exception) {
            $current = ShopYourVibeDraft::find($this->draftId);
            if ($current && (($current->pending && ! $this->discard) || (int) $current->revision !== $this->revision)) {
                Cache::put(ShopYourVibeWorkflow::progressKey($draft->collection_gid), [
                    'state' => 'skipped',
                    'kind' => 'refresh',
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
                'kind' => 'refresh',
                'done' => null,
                'total' => null,
                'message' => $exception->getMessage(),
            ], 3600);
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

    public function failed(Throwable $exception): void
    {
        $draft = ShopYourVibeDraft::find($this->draftId);
        if (! $draft) {
            return;
        }
        $key = ShopYourVibeWorkflow::progressKey($draft->collection_gid);
        $current = Cache::get($key);
        if (is_array($current) && in_array($current['state'] ?? null, ['success', 'failed', 'skipped'], true)) {
            return;
        }
        Cache::put($key, [
            'state' => 'failed',
            'kind' => 'refresh',
            'done' => null,
            'total' => null,
            'message' => $exception->getMessage(),
        ], 3600);
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
