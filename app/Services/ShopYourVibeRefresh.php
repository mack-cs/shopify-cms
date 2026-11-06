<?php

namespace App\Services;

use App\Exceptions\ShopYourVibeRefreshExpiredException;
use App\Jobs\RefreshShopYourVibeDraft;
use App\Models\ShopYourVibeDraft;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Str;
use RuntimeException;

/** Durable fencing lives on the collection's unique draft row, independently of cache eviction. */
class ShopYourVibeRefresh
{
    public const QUEUED_SECONDS = 960;
    public const RUNNING_SECONDS = 900; // Worker timeout 840 < lease 900 < queue retry_after 960.

    public function active(?array $state): bool
    {
        return in_array($state['state'] ?? null, ['queued', 'running'], true)
            && ($state['expires_at'] ?? 0) > now()->timestamp;
    }

    public function request(int $id, int $revision, ?int $userId, bool $dispatch = true): array
    {
        return DB::transaction(function () use ($id, $revision, $userId, $dispatch): array {
            $draft = $this->lockedDraft($id) ?? throw new RuntimeException('This Shop Your Vibe draft no longer exists.');
            if ($this->active($draft->refresh_state)) {
                $this->cache($draft);

                return ['created' => false, 'operation' => $draft->refresh_state];
            }
            if ($draft->revision !== $revision) {
                throw new RuntimeException('This draft changed. Reload before refreshing.');
            }
            app(ShopYourVibeEditing::class)->readPublication($draft, $userId);
            if ($draft->pending || $draft->remote_jobs || $draft->operation
                || in_array($draft->status, ['pushing', 'creating', 'deleting'], true)) {
                throw new RuntimeException('Pending work must be resolved before refreshing.');
            }
            $state = [
                'token' => (string) Str::uuid(), 'state' => 'queued', 'revision' => $revision,
                'user_id' => $userId, 'queued_at' => now()->timestamp,
                'expires_at' => now()->timestamp + self::QUEUED_SECONDS,
                'kind' => 'refresh', 'stage' => 'Refreshing collection products',
                'done' => null, 'total' => null, 'message' => 'Refreshing collection products',
            ];
            $draft->update(['refresh_state' => $state]);
            $this->cache($draft);
            if ($dispatch) {
                $job = new RefreshShopYourVibeDraft($id, $revision, false, $userId, $state['token']);
                $queue = config('queue.connections.shop-your-vibe');
                if (($queue['driver'] ?? null) === 'database'
                    && in_array($queue['connection'] ?? null, [null, DB::getDefaultConnection()], true)) {
                    // Queue insert and claim commit atomically on the configured shared database.
                    Bus::dispatch($job->beforeCommit());
                } else {
                    // A separate backend must never deliver the job before its fencing token is visible.
                    DB::afterCommit(function () use ($job, $id, $state): void {
                        try {
                            Bus::dispatch($job);
                        } catch (\Throwable $exception) {
                            $this->finish($id, $state['token'], 'failed', $exception->getMessage());
                            throw $exception;
                        }
                    });
                }
            }

            return ['created' => true, 'operation' => $state];
        }, 5);
    }

    public function start(int $id, string $token): bool
    {
        return DB::transaction(function () use ($id, $token): bool {
            $draft = $this->lockedDraft($id) ?? throw new RuntimeException('This Shop Your Vibe draft no longer exists.');
            $state = $draft->refresh_state;
            if (($state['token'] ?? null) !== $token || ($state['state'] ?? null) !== 'queued' || ! $this->active($state)) {
                return false;
            }
            $state['state'] = 'running';
            $state['started_at'] = $state['heartbeat_at'] = now()->timestamp;
            $state['expires_at'] = now()->timestamp + self::RUNNING_SECONDS;
            $draft->update(['refresh_state' => $state]);
            $this->cache($draft);

            return true;
        });
    }

    public function status(int $id): ?array
    {
        return DB::transaction(function () use ($id): ?array {
            $draft = $this->lockedDraft($id);
            if (! $draft || ! ($state = $draft->refresh_state)) {
                return null;
            }
            if (in_array($state['state'], ['queued', 'running'], true) && ! $this->active($state)) {
                $state = array_replace($state, ['state' => 'failed', 'expired_at' => now()->timestamp,
                    'message' => 'The refresh expired. Existing products were kept. Retry to refresh this collection.']);
                $draft->update(['refresh_state' => $state]);
            }
            $this->cache($draft);

            return $state;
        });
    }

    /** Called under the publication transaction's draft row lock as well as before/after remote reads. */
    public function assertCurrent(ShopYourVibeDraft $draft, string $token): void
    {
        $state = $draft->refresh_state;
        if (($state['token'] ?? null) !== $token || ($state['state'] ?? null) !== 'running' || ! $this->active($state)) {
            throw new ShopYourVibeRefreshExpiredException('This refresh attempt expired or was replaced. Existing products were kept.');
        }
    }

    public function heartbeat(int $id, string $token, array $progress = []): void
    {
        DB::transaction(function () use ($id, $token, $progress): void {
            $draft = $this->lockedDraft($id) ?? throw new RuntimeException('This Shop Your Vibe draft no longer exists.');
            $this->assertCurrent($draft, $token);
            if ($draft->pending || $draft->revision !== $draft->refresh_state['revision']) {
                throw new RuntimeException('Refresh skipped. Newer local changes were kept.');
            }
            app(ShopYourVibeEditing::class)->readPublication($draft, $draft->refresh_state['user_id']);
            $state = array_replace($draft->refresh_state, $progress, ['heartbeat_at' => now()->timestamp]);
            // A hard deadline is never extended: a stuck worker cannot keep this operation alive forever.
            $draft->update(['refresh_state' => $state]);
            $this->cache($draft);
        });
    }

    public function finish(int $id, string $token, string $status, string $message): bool
    {
        return DB::transaction(function () use ($id, $token, $status, $message): bool {
            $draft = $this->lockedDraft($id);
            if (! $draft || ($draft->refresh_state['token'] ?? null) !== $token || ! $this->active($draft->refresh_state)) {
                return false;
            }
            $state = array_replace($draft->refresh_state, [
                'state' => $status, 'message' => $message, 'finished_at' => now()->timestamp,
                'done' => null, 'total' => null,
            ]);
            $draft->update(['refresh_state' => $state]);
            $this->cache($draft);

            return true;
        });
    }

    private function cache(ShopYourVibeDraft $draft): void
    {
        DB::afterCommit(function () use ($draft): void {
            DB::transaction(function () use ($draft): void {
                // Read the latest attempt under the same row lock: delayed callbacks cannot restore old progress.
                $current = $this->lockedDraft($draft->id);
                if (! $current || ! ($state = $current->refresh_state)) {
                    return;
                }
                Cache::put(ShopYourVibeWorkflow::progressKey($current->collection_gid), $state,
                    in_array($state['state'], ['queued', 'running'], true) ? max(1, $state['expires_at'] - now()->timestamp) : 3600);
            });
        });
    }

    private function lockedDraft(int $id): ?ShopYourVibeDraft
    {
        if (DB::connection()->getDriverName() === 'sqlite') {
            // SQLite ignores FOR UPDATE. Take its writer reservation before the first read,
            // avoiding the deferred-read-to-write upgrade deadlock between simultaneous requests.
            DB::table('shop_your_vibe_drafts')->where('id', $id)->update(['id' => DB::raw('id')]);
        }

        return ShopYourVibeDraft::lockForUpdate()->find($id);
    }
}
