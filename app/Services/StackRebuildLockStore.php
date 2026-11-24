<?php

namespace App\Services;

use App\Jobs\RebuildShopifyStackImagesJob;
use Illuminate\Database\QueryException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Database ownership for one batch and stack set.
 * Every claim carries an owner token. Release, heartbeat, and recovery match that token.
 * The claim stays until its owner finishes. Age alone does not release a queued claim.
 */
final class StackRebuildLockStore
{
    /** A started worker that stops heartbeating is abandoned after the job timeout. */
    public const RUNNING_ABANDONED_SECONDS = 3600;

    /** A claim that never started is abandoned only after a day, so a queued job survives the old one-hour lock. */
    public const QUEUED_ABANDONED_SECONDS = 86400;

    public function __construct(private readonly ?RebuildShopifyStackImagesJob $job = null) {}

    public static function boundTo(RebuildShopifyStackImagesJob $job): self
    {
        return new self($job);
    }

    public function lock(string $name, int $seconds = 0): StackRebuildClaimLock
    {
        $owner = $this->job?->claimOwner;
        if (! is_string($owner) || $owner === '') {
            $owner = (string) Str::uuid();
        }

        return new StackRebuildClaimLock($name, $owner, $this->job);
    }

    /** Laravel records this name for a missing-model release. That path uses the cache store and cannot delete a database claim. */
    public function getName(): string
    {
        return (string) config('cache.default');
    }

    public function owns(string $name, string $owner): bool
    {
        if ($name === '' || $owner === '') {
            return false;
        }

        return DB::table('stack_rebuild_claims')
            ->where('claim_key', $name)
            ->where('owner', $owner)
            ->exists();
    }

    public function markRunning(string $name, string $owner): bool
    {
        if ($name === '' || $owner === '') {
            return false;
        }

        $now = now();
        $started = DB::table('stack_rebuild_claims')
            ->where('claim_key', $name)
            ->where('owner', $owner)
            ->whereNull('started_at')
            ->update([
                'started_at' => $now,
                'heartbeat_at' => $now,
                'updated_at' => $now,
            ]);
        if ($started === 1) {
            return true;
        }

        return DB::table('stack_rebuild_claims')
            ->where('claim_key', $name)
            ->where('owner', $owner)
            ->update([
                'heartbeat_at' => $now,
                'updated_at' => $now,
            ]) === 1;
    }

    public function release(string $name, string $owner): int
    {
        if ($name === '' || $owner === '') {
            return 0;
        }

        return DB::table('stack_rebuild_claims')
            ->where('claim_key', $name)
            ->where('owner', $owner)
            ->delete();
    }
}

final class StackRebuildClaimLock
{
    public function __construct(
        private readonly string $name,
        private readonly string $owner,
        private readonly ?RebuildShopifyStackImagesJob $job = null,
    ) {}

    public function get(): bool
    {
        try {
            DB::table('stack_rebuild_claims')->insert([
                'claim_key' => $this->name,
                'owner' => $this->owner,
                'heartbeat_at' => now(),
                'created_at' => now(),
                'updated_at' => now(),
            ]);
            $this->remember();

            return true;
        } catch (QueryException $exception) {
            if (! $this->isDuplicate($exception)) {
                throw $exception;
            }
        }

        if (! $this->recover()) {
            return false;
        }
        $this->remember();

        return true;
    }

    public function forceRelease(): void
    {
        app(StackRebuildLockStore::class)->release($this->name, $this->owner);
    }

    private function remember(): void
    {
        if ($this->job !== null) {
            $this->job->claimAcquired = true;
        }
    }

    private function recover(): bool
    {
        return DB::transaction(function (): bool {
            $row = DB::table('stack_rebuild_claims')->where('claim_key', $this->name)->lockForUpdate()->first();
            if (! $row || ! $this->abandoned($row)) {
                return false;
            }

            return DB::table('stack_rebuild_claims')
                ->where('id', $row->id)
                ->where('owner', $row->owner)
                ->where('heartbeat_at', $row->heartbeat_at)
                ->update([
                    'owner' => $this->owner,
                    'heartbeat_at' => now(),
                    'started_at' => null,
                    'updated_at' => now(),
                ]) === 1;
        });
    }

    private function abandoned(object $row): bool
    {
        $heartbeat = Carbon::parse($row->heartbeat_at ?? $row->created_at);
        $seconds = $row->started_at !== null
            ? StackRebuildLockStore::RUNNING_ABANDONED_SECONDS
            : StackRebuildLockStore::QUEUED_ABANDONED_SECONDS;

        return $heartbeat->lte(now()->subSeconds($seconds));
    }

    private function isDuplicate(QueryException $exception): bool
    {
        $state = (string) ($exception->errorInfo[0] ?? '');

        return $state === '23000' || str_contains(strtolower($exception->getMessage()), 'unique');
    }
}
