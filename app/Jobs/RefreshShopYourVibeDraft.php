<?php

namespace App\Jobs;

use App\Exceptions\ShopYourVibeRefreshExpiredException;
use App\Models\ShopYourVibeDraft;
use App\Services\AdminNotification;
use App\Services\ShopYourVibeRefresh;
use App\Services\ShopYourVibeShopify;
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

    public bool $failOnTimeout = true;

    private ?string $refreshToken = null;

    public function __construct(
        private readonly int $draftId,
        private readonly int $revision,
        private readonly bool $discard,
        private readonly ?int $userId = null,
        ?string $refreshToken = null,
    ) {
        $this->refreshToken = $refreshToken;
        $this->onConnection('shop-your-vibe');
        $this->onQueue('shop-your-vibe');
    }

    public function handle(ShopYourVibeWorkflow $workflow): void
    {
        $refresh = app(ShopYourVibeRefresh::class);
        $draft = ShopYourVibeDraft::findOrFail($this->draftId);
        // Legacy payloads may claim an idle collection, but cannot adopt a newer queued attempt.
        if ($this->refreshToken === null) {
            if ($draft->revision !== $this->revision || $refresh->active($draft->refresh_state)) {
                return;
            }
            if ($draft->pending) {
                throw new \RuntimeException('Pending changes require an audited discard with a reason. Refresh did not discard them.');
            }
            $claim = $refresh->request($this->draftId, $this->revision, $this->userId, false);
            if (! $claim['created']) {
                return;
            }
            $this->refreshToken = $claim['operation']['token'];
        }
        $token = $this->refreshToken;
        if (! $refresh->start($this->draftId, $token)) {
            return; // Duplicate delivery, expired queue entry, or an older attempt.
        }

        try {
            if ($draft->pending || $draft->revision !== $this->revision) {
                $refresh->finish($this->draftId, $token, 'skipped', 'Refresh skipped. Newer local changes were kept.');

                return;
            }
            $workflow->trackRefreshAttempt(fn () => $refresh->heartbeat($this->draftId, $token));
            app(ShopYourVibeShopify::class)->syncStoredCollections($this->userId);
            $workflow->trackProductProgress(function (int $done, int $total, string $gid = '', string $title = '') use ($refresh, $token, $draft): void {
                $refresh->heartbeat($this->draftId, $token,
                    ShopYourVibeWorkflow::productProgressPayload($draft->collection_gid, $gid, $done, $total, $title) + ['stage_gid' => $gid]);
            });
            $workflow->refresh($this->draftId, $this->revision, false, true, $this->userId, $token);
        } catch (ShopYourVibeRefreshExpiredException) {
            return; // Expired workers cannot change a newer attempt's progress or notification.
        } catch (Throwable $exception) {
            $current = ShopYourVibeDraft::find($this->draftId);
            $changed = $current && ($current->pending || $current->revision !== $this->revision);
            if ($refresh->finish($this->draftId, $token, $changed ? 'skipped' : 'failed',
                $changed ? 'Refresh skipped. Newer local changes were kept.' : $exception->getMessage())) {
                $this->notify($changed ? 'Shop Your Vibe refresh skipped' : 'Shop Your Vibe refresh failed',
                    $changed ? 'Local edits were kept.' : $exception->getMessage(), false);
            }
            if (! $changed) {
                throw $exception;
            }

            return;
        } finally {
            $workflow->trackRefreshAttempt(null);
            $workflow->trackProductProgress(null);
        }

        $this->notify('Shop Your Vibe refreshed', 'Latest Shopify collections and this draft have been loaded.', true);
    }

    public function failed(Throwable $exception): void
    {
        if ($this->refreshToken !== null) {
            app(ShopYourVibeRefresh::class)->finish($this->draftId, $this->refreshToken, 'failed', $exception->getMessage());
        }
    }

    private function notify(string $title, string $body, bool $success): void
    {
        if ($this->userId) {
            $notification = Notification::make()->title($title)->body($body);
            AdminNotification::sendToUserId($success ? $notification->success() : $notification->danger(), $this->userId);
        }
    }
}
