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

class HydrateShopYourVibeDraft implements ShouldBeUnique, ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    public int $timeout = 840;

    public int $uniqueFor = 960;

    public function __construct(
        private readonly string $collectionGid,
        private readonly ?int $userId = null,
    ) {
        $this->onConnection('shop-your-vibe');
        $this->onQueue('shop-your-vibe');
    }

    public function uniqueId(): string
    {
        return 'syv-hydrate:'.$this->collectionGid;
    }

    public static function releaseUniqueLock(string $collectionGid): void
    {
        (new UniqueLock(app(CacheRepository::class)))->release(new self($collectionGid));
    }

    /**
     * @return array<int, int>
     */
    public function backoff(): array
    {
        return [15, 60];
    }

    public function handle(ShopYourVibeWorkflow $workflow): void
    {
        $existing = ShopYourVibeDraft::query()->where('collection_gid', $this->collectionGid)->first();
        if ($existing && $existing->pending) {
            Cache::put(ShopYourVibeWorkflow::progressKey($this->collectionGid), [
                'state' => 'skipped',
                'done' => null,
                'total' => null,
                'message' => 'Refresh skipped. Newer local changes were kept.',
            ], 120);

            return;
        }
        if ($existing && ShopYourVibeWorkflow::productsAreLoaded($existing->desired, $existing->collection_gid)) {
            Cache::put(ShopYourVibeWorkflow::progressKey($this->collectionGid), [
                'state' => 'success',
                'done' => null,
                'total' => null,
                'message' => ShopYourVibeWorkflow::HYDRATION_SUCCESS_MESSAGE,
            ], 120);

            return;
        }

        Cache::put(ShopYourVibeWorkflow::progressKey($this->collectionGid), [
            'state' => 'running',
            'stage' => 'Loading this collection',
            'done' => null,
            'total' => null,
            'message' => ShopYourVibeWorkflow::LOADING_PRODUCTS_MESSAGE,
        ], 600);

        $workflow->trackProductProgress(function (int $done, int $total, string $gid = '', string $title = ''): void {
            $payload = ShopYourVibeWorkflow::productProgressPayload($this->collectionGid, $gid, $done, $total, $title);
            Cache::put(ShopYourVibeWorkflow::progressKey($this->collectionGid), [
                'state' => 'running',
                'stage' => $payload['stage'],
                'done' => $payload['done'],
                'total' => $payload['total'],
                'percent' => $payload['percent'],
                'stage_gid' => $gid,
                'message' => $payload['message'],
            ], 600);
        });

        try {
            $draft = $existing
                ? $workflow->refresh($existing->id, $existing->revision, false, true)
                : $workflow->open($this->collectionGid);
            if ($draft->pending) {
                Cache::put(ShopYourVibeWorkflow::progressKey($this->collectionGid), [
                    'state' => 'skipped',
                    'done' => null,
                    'total' => null,
                    'message' => 'Refresh skipped. Newer local changes were kept.',
                ], 120);

                return;
            }
            if (! ShopYourVibeWorkflow::productsAreLoaded($draft->desired, $this->collectionGid)) {
                $draft = $workflow->refresh($draft->id, $draft->revision, false, true);
            }
            if ($draft->pending) {
                Cache::put(ShopYourVibeWorkflow::progressKey($this->collectionGid), [
                    'state' => 'skipped',
                    'done' => null,
                    'total' => null,
                    'message' => 'Refresh skipped. Newer local changes were kept.',
                ], 120);

                return;
            }
            Cache::put(ShopYourVibeWorkflow::progressKey($this->collectionGid), [
                'state' => 'success',
                'done' => null,
                'total' => null,
                'message' => ShopYourVibeWorkflow::HYDRATION_SUCCESS_MESSAGE,
            ], 120);
        } catch (Throwable $exception) {
            $current = ShopYourVibeDraft::query()->where('collection_gid', $this->collectionGid)->first();
            if ($current && $current->pending) {
                Cache::put(ShopYourVibeWorkflow::progressKey($this->collectionGid), [
                    'state' => 'skipped',
                    'done' => null,
                    'total' => null,
                    'message' => 'Refresh skipped. Newer local changes were kept.',
                ], 120);

                return;
            }
            if ($current && ShopYourVibeWorkflow::productsAreLoaded($current->desired, $current->collection_gid)) {
                Cache::put(ShopYourVibeWorkflow::progressKey($this->collectionGid), [
                    'state' => 'success',
                    'done' => null,
                    'total' => null,
                    'message' => ShopYourVibeWorkflow::HYDRATION_SUCCESS_MESSAGE,
                ], 120);

                return;
            }
            Cache::put(ShopYourVibeWorkflow::progressKey($this->collectionGid), [
                'state' => 'failed',
                'done' => null,
                'total' => null,
                'message' => ShopYourVibeWorkflow::HYDRATION_FAILED_MESSAGE,
            ], 600);
            $this->notify('Shop Your Vibe could not load this collection', $exception->getMessage(), false);

            throw $exception;
        } finally {
            $workflow->trackProductProgress(null);
        }
    }

    private function notify(string $title, string $body, bool $success): void
    {
        if (! $this->userId) {
            return;
        }

        $notification = Notification::make()->title($title)->body($body);
        $notification = $success ? $notification->success() : $notification->danger();
        AdminNotification::sendToUserId($notification, $this->userId);
    }
}
