<?php

namespace App\Services;

use App\Enums\PermissionEnum;
use App\Enums\RolesEnum;
use App\Models\ChangeLog;
use App\Models\ShopYourVibeDraft;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use RuntimeException;

/** All coordination uses the draft row lock; no transaction spans a Shopify request. */
class ShopYourVibeEditing
{
    public const SECONDS = 840;

    public ?string $token = null;

    public static function permitted(?User $user): bool
    {
        return $user && ($user->is_active ?? $user->fresh()?->is_active) && ($user->hasAnyRole([RolesEnum::SuperAdmin->value, RolesEnum::Admin->value])
            || ($user->can(PermissionEnum::ShopifyPushProducts->value) && $user->can(PermissionEnum::ProductEdit->value)));
    }

    private function actor(): User
    {
        $user = auth()->id() ? User::find(auth()->id()) : null;
        if (! self::permitted($user)) {
            throw new RuntimeException('You do not have permission to edit or discard Shop Your Vibe changes.');
        }

        return $user;
    }

    private function revision(ShopYourVibeDraft $draft, int $revision): void
    {
        if ($draft->revision !== $revision) {
            throw new RuntimeException('This draft changed. Reload and review the current revision before editing.');
        }
    }

    private function busy(ShopYourVibeDraft $draft): void
    {
        if (in_array($draft->status, ['pushing', 'creating', 'deleting'], true) || $draft->remote_jobs || $draft->operation) {
            throw new RuntimeException('A Shopify operation needs confirmation. Editing and discard are blocked until it is resolved.');
        }
    }

    /** Only an unowned, checkpoint-free legacy flag can be archived without remote recovery. */
    private function abandonedLegacyPush(ShopYourVibeDraft $draft): bool
    {
        if ($draft->status !== 'pushing' || ! empty($draft->editing_state)
            || ! $draft->pending || $draft->remote_jobs || $draft->operation || $draft->progress
            || ! $draft->updated_at || $draft->updated_at->gte(now()->subDay())
            || Cache::has('shop-your-vibe-op:'.$draft->collection_gid)) {
            return false;
        }
        $queue = config('queue.connections.shop-your-vibe');
        if (($queue['driver'] ?? null) !== 'database'
            || ! in_array($queue['connection'] ?? null, [null, $draft->getConnectionName(), config('database.default')], true)) {
            return false;
        }
        // The job row remains present while reserved/running. Lock this range
        // until discard commits so an enqueue cannot race the idle check.
        return DB::connection($draft->getConnectionName())->table($queue['table'] ?? 'jobs')
            ->where('queue', $queue['queue'] ?? 'shop-your-vibe')->lockForUpdate()->get(['id'])->isEmpty();
    }

    public function active(array $state): bool
    {
        return isset($state['expires_at']) && now()->lt($state['expires_at']);
    }

    public function acquire(int $id, int $revision): ShopYourVibeDraft
    {
        return DB::transaction(function () use ($id, $revision) {
            $user = $this->actor();
            $draft = ShopYourVibeDraft::lockForUpdate()->findOrFail($id);
            $this->revision($draft, $revision);
            $state = $draft->editing_state ?? [];
            if (in_array($draft->status, ['pushing', 'creating', 'deleting'], true)) {
                throw new RuntimeException('Wait for the active Shopify operation before reacquiring this editing lock.');
            }
            if (($draft->operation || $draft->remote_jobs) && ($state['owner_id'] ?? null) !== $user->id) {
                throw new RuntimeException('The original editor must reconcile the interrupted Shopify operation before this scope can change owners.');
            }
            if ($this->active($state) && ($state['owner_id'] ?? null) !== $user->id) {
                throw new RuntimeException(($state['owner_name'] ?? 'Another user').' is currently editing this collection. Their pending changes are protected for 14 minutes after their last activity.');
            }
            if ($draft->pending && ($state['pending_owner_id'] ?? null) !== $user->id) {
                throw new RuntimeException(($state['pending_owner_name'] ?? 'An unidentified previous editor').' has pending changes. Review and discard them with a reason after the lock expires.');
            }
            $this->token = (string) Str::uuid();
            $state = array_merge($state, ['owner_id' => $user->id, 'owner_name' => $user->name,
                'token_hash' => hash('sha256', $this->token), 'last_activity_at' => now()->toISOString(),
                'expires_at' => now()->addSeconds(self::SECONDS)->toISOString(), 'push' => null]);
            $draft->update(['editing_state' => $state]);

            return $draft;
        });
    }

    /** Called inside the same locked transaction as the draft mutation. */
    public function authorize(ShopYourVibeDraft $draft, int $revision): void
    {
        // Existing internal, unauthenticated workflow callers have no browser identity.
        // They may never touch a draft that has entered collaborative editing.
        if (! auth()->check() && empty($draft->editing_state)) {
            return;
        }
        $user = $this->actor();
        $this->revision($draft, $revision);
        $state = $draft->editing_state ?? [];
        if ($state === []) {
            $claimed = $this->acquire($draft->id, $revision);
            $draft->editing_state = $claimed->editing_state;
            $state = $draft->editing_state;
        }
        if (! $this->active($state)) {
            throw new RuntimeException('Your editing lock expired. Reload, review this revision and choose Start editing to reacquire it. Pending changes were kept.');
        }
        if (($state['owner_id'] ?? null) !== $user->id || ! $this->token
            || ! hash_equals($state['token_hash'] ?? '', hash('sha256', $this->token))) {
            throw new RuntimeException(($state['owner_name'] ?? 'Another editor').' owns this editing lock. Start editing explicitly when the scope is available.');
        }
        if ($draft->pending && ($state['pending_owner_id'] ?? null) !== $user->id) {
            throw new RuntimeException('Another editor owns these pending changes. They cannot be adopted into your push.');
        }
        $state['last_activity_at'] = now()->toISOString();
        $state['expires_at'] = now()->addSeconds(self::SECONDS)->toISOString();
        $draft->editing_state = $state;
        $draft->save();
    }

    public function heartbeat(int $id, int $revision): array
    {
        return DB::transaction(function () use ($id, $revision) {
            $draft = ShopYourVibeDraft::lockForUpdate()->findOrFail($id);
            $this->busy($draft);
            $this->authorize($draft, $revision);

            return $this->view($draft);
        });
    }

    public function readPublication(ShopYourVibeDraft $draft, ?int $actorId): void
    {
        $state = $draft->editing_state ?? [];
        if ($this->active($state) && ($state['owner_id'] ?? null) !== $actorId) {
            throw new RuntimeException(($state['owner_name'] ?? 'Another editor').' is editing this draft. A refresh or hydration cannot replace their editing state.');
        }
    }

    public function record(ShopYourVibeDraft $draft, string $type, array $details): void
    {
        if (empty($draft->editing_state)) {
            return;
        }
        $user = $this->actor();
        $state = $draft->editing_state;
        $state['pending_owner_id'] = $draft->pending ? $user->id : null;
        $state['pending_owner_name'] = $draft->pending ? $user->name : null;
        $state['changed_at'] = now()->toISOString();
        $state['pending_revision'] = $draft->revision;
        $target = $details['collection_gid'] ?? $details['key'] ?? $draft->collection_gid;
        // Keep the latest pending intent per operation/target; repeated drags
        // must not turn the shared JSON into an unbounded event log.
        $state['changes'] = array_values(array_filter($state['changes'] ?? [], fn ($change) => $change['type'] !== $type || $change['target'] !== $target));
        $state['changes'][] = ['editor_id' => $user->id, 'editor_name' => $user->name,
            'collection_gid' => $draft->collection_gid, 'target' => $target,
            'type' => $type, 'at' => now()->toISOString(), 'revision' => $draft->revision, 'details' => $details];
        if (! $draft->pending) {
            $state['changes'] = [];
        }
        $state['push'] = null;
        $draft->update(['editing_state' => $state]);
    }

    public function ticket(ShopYourVibeDraft $draft, string $kind = 'push'): void
    {
        if (empty($draft->editing_state)) {
            return;
        }
        $state = $draft->editing_state;
        $user = $this->actor();
        $state['push'] = ['id' => (string) Str::uuid(), 'actor_id' => $user->id,
            'revision' => $draft->revision, 'reviewed_revision' => $draft->revision - 1,
            'desired_hash' => hash('sha256', json_encode($draft->desired)), 'kind' => $kind,
            'expires_at' => $state['expires_at'], 'token_hash' => $state['token_hash']];
        $draft->update(['editing_state' => $state]);
    }

    public function validateJob(int $id, ?array $ticket, bool $initial = true): void
    {
        DB::transaction(function () use ($id, $ticket, $initial) {
            $draft = ShopYourVibeDraft::lockForUpdate()->findOrFail($id);
            if (empty($draft->editing_state) && $ticket === null && auth()->check() === false) {
                return; // Internal legacy workflow calls only; queue jobs reject missing tickets.
            }
            $state = $draft->editing_state ?? [];
            $user = isset($ticket['actor_id']) ? User::find($ticket['actor_id']) : null;
            if (! $ticket || ! self::permitted($user) || ($state['push']['id'] ?? null) !== ($ticket['id'] ?? null)
                || $draft->revision !== ($ticket['revision'] ?? null)
                || ($state['owner_id'] ?? null) !== $user->id || ! $this->active($state)
                || now()->gte($ticket['expires_at'])
                || ($state['token_hash'] ?? null) !== ($ticket['token_hash'] ?? null)
                || ($initial && hash('sha256', json_encode($draft->desired)) !== $ticket['desired_hash'])) {
                throw new RuntimeException('Queued Shopify authorization expired or changed. No new writes are authorized; reload and review before retrying.');
            }
        });
    }

    public function discard(int $id, int $revision, string $reason): ShopYourVibeDraft
    {
        if (mb_strlen(preg_replace('/[^\p{L}\p{N}]/u', '', $reason)) < 10 || mb_strlen($reason) > 4000) {
            throw ValidationException::withMessages(['discardReason' => 'Enter a reason with at least 10 letters or numbers (maximum 4000 characters).']);
        }

        return DB::transaction(function () use ($id, $revision, $reason) {
            $user = $this->actor();
            $draft = ShopYourVibeDraft::lockForUpdate()->findOrFail($id);
            $this->revision($draft, $revision);
            $abandonedLegacy = $this->abandonedLegacyPush($draft);
            if (! $abandonedLegacy) {
                $this->busy($draft);
            }
            $state = $draft->editing_state ?? [];
            if ($this->active($state) && ($state['owner_id'] ?? null) !== $user->id) {
                throw new RuntimeException('Discard blocked: '.$state['owner_name'].' still has an active editing lock. Administrators cannot bypass it.');
            }
            if (! $draft->pending) {
                throw new RuntimeException('There are no pending changes to discard.');
            }
            // Missing-collection tombstones remain sticky even if the old baseline predates deletion.
            $restored = $draft->snapshot;
            $restored['missing_collection_ids'] = array_values(array_unique(array_merge($restored['missing_collection_ids'] ?? [], $draft->desired['missing_collection_ids'] ?? [])));
            foreach ($restored['missing_collection_ids'] as $gid) {
                if (isset($restored['collections'][$gid])) {
                    $restored['collections'][$gid]['unavailable'] = true;
                }
            }
            $entry = ChangeLog::create(['changed_by' => $user->id, 'source' => 'shop_your_vibe_discard',
                'model_type' => ShopYourVibeDraft::class, 'model_id' => $draft->id, 'field' => 'parent_draft',
                'old_value' => json_encode(['confirmed' => $draft->snapshot, 'proposed' => $draft->desired,
                    'editor_id' => $state['pending_owner_id'] ?? null, 'editor_name' => $state['pending_owner_name'] ?? 'Unknown legacy editor',
                    'revision' => $draft->revision, 'scope' => $draft->collection_gid,
                    'previous_status' => $draft->status, 'abandoned_legacy_push' => $abandonedLegacy,
                    'collection' => $draft->desired['parent']['title'] ?? $draft->collection_gid,
                    'changed_at' => $state['changed_at'] ?? $draft->updated_at?->toISOString(), 'changes' => $state['changes'] ?? []], JSON_THROW_ON_ERROR),
                'new_value' => json_encode(['reason' => $reason, 'discarded_by_id' => $user->id, 'discarded_by_name' => $user->name,
                    'at' => now()->toISOString(), 'result' => 'discarded_locally', 'restored' => $restored], JSON_THROW_ON_ERROR)]);
            $draft->update(['desired' => $restored, 'snapshot' => $restored, 'pending' => false, 'status' => 'synced',
                'last_error' => null, 'progress' => [], 'revision' => $draft->revision + 1,
                'editing_state' => ['last_discard_id' => $entry->id, 'last_discard_by' => $user->name]]);

            return $draft;
        });
    }

    public function view(ShopYourVibeDraft $draft): array
    {
        $state = $draft->editing_state ?? [];
        $active = $this->active($state);
        $mine = ($state['owner_id'] ?? null) === auth()->id();

        return ['active' => $active, 'mine' => $mine, 'owner' => $state['owner_name'] ?? null,
            'pending' => $draft->pending, 'revision' => $draft->revision,
            'pending_owner' => $state['pending_owner_name'] ?? ($draft->pending ? 'Unknown legacy editor' : null),
            'changed_at' => $state['changed_at'] ?? null, 'expires_at' => $state['expires_at'] ?? null,
            'seconds' => $active ? max(0, now()->diffInSeconds($state['expires_at'], false)) : 0,
            'blocked' => ($active && ! $mine) || ($draft->pending && ($state['pending_owner_id'] ?? null) !== auth()->id()),
            'changes' => $state['changes'] ?? [], 'last_discard_id' => $state['last_discard_id'] ?? null,
            'last_discard_by' => $state['last_discard_by'] ?? null];
    }
}
