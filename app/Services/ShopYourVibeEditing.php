<?php

namespace App\Services;

use App\Enums\PermissionEnum;
use App\Enums\RolesEnum;
use App\Models\ChangeLog;
use App\Models\ShopYourVibeDraft;
use App\Models\SyvCollectionSortState;
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

    public const LAYOUT = 'layout';

    public ?string $token = null;

    public static function sortScope(string $gid): string
    {
        return 'sort:'.$gid;
    }

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

    /** @param array<string, mixed> $state */
    private function claimReleasedLock(ShopYourVibeDraft $draft, User $user, array $state): void
    {
        $this->token = (string) Str::uuid();
        $draft->editing_state = array_merge($state, [
            'owner_id' => $user->id,
            'owner_name' => $user->name,
            'token_hash' => hash('sha256', $this->token),
            'last_activity_at' => now()->toISOString(),
            'expires_at' => now()->addSeconds(self::SECONDS)->toISOString(),
            'push' => null,
        ]);
        $draft->save();
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
        $locks = is_array($state['locks'] ?? null) ? $state['locks'] : [];
        unset($state['locks']);
        if ($locks !== [] && ! $this->active($state) && empty($state['owner_id']) && empty($state['token_hash'])) {
            $this->authorizeScope($draft, self::LAYOUT);

            return;
        }
        if ($state === [] && $locks === []) {
            $claimed = $this->acquire($draft->id, $revision);
            $draft->editing_state = $claimed->editing_state;
            $state = $draft->editing_state;
        }
        $state = $draft->editing_state ?? [];
        if (! $this->active($state)) {
            $pendingOwner = $state['pending_owner_id'] ?? null;
            $released = empty($state['owner_id']) && empty($state['token_hash']);
            if ($released && $draft->pending && (int) $pendingOwner === (int) $user->id) {
                $this->claimReleasedLock($draft, $user, $state);

                return;
            }
            if ($draft->pending && $pendingOwner && (int) $pendingOwner !== (int) $user->id) {
                throw new RuntimeException(($state['pending_owner_name'] ?? 'Another editor').' has pending changes. Review and discard them with a reason after the lock expires.');
            }
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

    public function handover(ShopYourVibeDraft $draft, User $reviewer, User $submitter): void
    {
        $state = $draft->editing_state ?? [];
        $state['owner_id'] = null;
        $state['owner_name'] = null;
        $state['token_hash'] = null;
        $state['expires_at'] = now()->subSecond()->toISOString();
        $state['pending_owner_id'] = $reviewer->id;
        $state['pending_owner_name'] = $reviewer->name;
        $state['review'] = [
            'reviewer_id' => $reviewer->id,
            'reviewer_name' => $reviewer->name,
            'submitted_by' => $submitter->id,
            'submitted_by_name' => $submitter->name,
            'submitted_revision' => $draft->revision,
            'submitted_at' => now()->toISOString(),
        ];
        $draft->editing_state = $state;
        $draft->save();
    }

    public function returnForChanges(ShopYourVibeDraft $draft, User $reviewer, string $feedback): void
    {
        $state = $draft->editing_state ?? [];
        $submitterId = $state['review']['submitted_by'] ?? $state['pending_owner_id'] ?? null;
        $submitterName = $state['review']['submitted_by_name'] ?? 'the original sorter';
        $state['owner_id'] = null;
        $state['owner_name'] = null;
        $state['token_hash'] = null;
        $state['expires_at'] = now()->subSecond()->toISOString();
        $state['pending_owner_id'] = $submitterId;
        $state['pending_owner_name'] = $submitterName;
        $state['review'] = [
            'reviewer_id' => $state['review']['reviewer_id'] ?? $reviewer->id,
            'reviewer_name' => $state['review']['reviewer_name'] ?? $reviewer->name,
            'submitted_by' => $submitterId,
            'submitted_by_name' => $submitterName,
            'submitted_revision' => $state['review']['submitted_revision'] ?? $draft->revision,
            'submitted_at' => $state['review']['submitted_at'] ?? null,
            'changes_requested_by' => $reviewer->id,
            'changes_requested_by_name' => $reviewer->name,
            'changes_requested_at' => now()->toISOString(),
            'changes_requested_feedback' => $feedback,
            'returned' => true,
        ];
        $draft->editing_state = $state;
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
        $locks = is_array($state['locks'] ?? null) ? $state['locks'] : [];
        if ($locks !== [] && ! $this->active($state) && empty($state['owner_id']) && empty($state['token_hash'])) {
            $this->recordScope($draft, self::LAYOUT, $type, $details);

            return;
        }
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
        $parent = $state;
        unset($parent['locks']);
        $layout = is_array($state['locks'][self::LAYOUT] ?? null) ? $state['locks'][self::LAYOUT] : [];
        $source = $this->active($parent) ? $parent : $layout;
        if (empty($source['expires_at']) || empty($source['token_hash'])) {
            throw new RuntimeException('Start editing before sending this change to Shopify.');
        }
        $state['push'] = ['id' => (string) Str::uuid(), 'actor_id' => $user->id,
            'revision' => $draft->revision, 'reviewed_revision' => $draft->revision - 1,
            'desired_hash' => hash('sha256', json_encode($draft->desired)), 'kind' => $kind,
            'expires_at' => $source['expires_at'], 'token_hash' => $source['token_hash'],
            'lock' => $source === $layout ? self::LAYOUT : 'parent'];
        $draft->update(['editing_state' => $state]);
    }

    public function validateJob(int $id, ?array $ticket, bool $initial = true): void
    {
        DB::transaction(function () use ($id, $ticket, $initial) {
            $draft = ShopYourVibeDraft::lockForUpdate()->findOrFail($id);
            if (is_string($ticket['scope'] ?? null) && $ticket['scope'] !== '') {
                $lock = $draft->editing_state['locks'][$ticket['scope']] ?? [];
                $push = is_array($lock['push'] ?? null) ? $lock['push'] : [];
                $gid = (string) ($ticket['scope_gid'] ?? '');
                $ids = array_column($draft->desired['collections'][$gid]['products'] ?? [], 'id');
                $user = isset($ticket['actor_id']) ? User::find($ticket['actor_id']) : null;
                if (! self::permitted($user) || ($push['id'] ?? null) !== ($ticket['id'] ?? null)
                    || (int) ($lock['revision'] ?? -1) !== (int) ($ticket['revision'] ?? -2)
                    || (int) ($lock['owner_id'] ?? 0) !== (int) ($user->id ?? 0) || ($lock['status'] ?? null) !== 'pushing'
                    || now()->gte($ticket['expires_at'] ?? now())
                    || ($lock['token_hash'] ?? null) !== ($ticket['token_hash'] ?? null)
                    || ($initial && hash('sha256', json_encode($ids)) !== ($ticket['desired_hash'] ?? null))) {
                    throw new RuntimeException('Queued Shopify authorization expired or changed. No new writes are authorized; reload and review before retrying.');
                }

                return;
            }
            if (empty($draft->editing_state) && $ticket === null && auth()->check() === false) {
                return; // Internal legacy workflow calls only; queue jobs reject missing tickets.
            }
            $state = $draft->editing_state ?? [];
            $user = isset($ticket['actor_id']) ? User::find($ticket['actor_id']) : null;
            if (($ticket['lock'] ?? null) === self::LAYOUT) {
                $lock = $state['locks'][self::LAYOUT] ?? [];
                if (! $ticket || ! self::permitted($user) || ($state['push']['id'] ?? null) !== ($ticket['id'] ?? null)
                    || $draft->revision !== ($ticket['revision'] ?? null)
                    || (int) ($lock['owner_id'] ?? 0) !== (int) ($user->id ?? 0) || ! $this->active($lock)
                    || now()->gte($ticket['expires_at'] ?? now())
                    || ($lock['token_hash'] ?? null) !== ($ticket['token_hash'] ?? null)
                    || ($initial && hash('sha256', json_encode($draft->desired)) !== ($ticket['desired_hash'] ?? null))) {
                    throw new RuntimeException('Queued Shopify authorization expired or changed. No new writes are authorized; reload and review before retrying.');
                }

                return;
            }
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
            foreach ($this->locks($draft) as $lock) {
                if ($this->active($lock) && (int) ($lock['owner_id'] ?? 0) !== (int) $user->id) {
                    throw new RuntimeException('Discard blocked: '.($lock['owner_name'] ?? 'Another editor').' still has an active editing lock. Administrators cannot bypass it.');
                }
            }
            if (! $draft->pending) {
                throw new RuntimeException('There are no pending changes to discard.');
            }
            $reviewerId = $state['review']['reviewer_id'] ?? null;
            if ($reviewerId && (int) $reviewerId !== (int) $user->id) {
                throw new RuntimeException('Only the assigned reviewer can discard a submitted sorting revision.');
            }
            app(ShopYourVibeSorting::class)->releaseUnpublished($draft, $user->id);
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
                    'editor_id' => $state['pending_owner_id'] ?? null, 'editor_name' => $state['pending_owner_name'] ?? 'Not recorded',
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

    public function acquireScope(int $id, int $scopeRevision, string $scope): ShopYourVibeDraft
    {
        return DB::transaction(function () use ($id, $scopeRevision, $scope) {
            $user = $this->actor();
            $draft = ShopYourVibeDraft::lockForUpdate()->findOrFail($id);
            if (in_array($draft->status, ['pushing', 'deleting'], true)) {
                throw new RuntimeException('Wait for the active Shopify operation before reacquiring this editing lock.');
            }
            $lock = $this->locks($draft)[$scope] ?? ['revision' => $scopeRevision];
            if (($lock['status'] ?? null) === 'pushing') {
                throw new RuntimeException('Wait for the Shopify push of this collection to finish before editing it.');
            }
            $this->assertScopeRevision($draft, $scope, $scopeRevision, true);
            $lock = $this->locks($draft)[$scope] ?? $lock;
            if ($this->active($lock) && (int) ($lock['owner_id'] ?? 0) !== (int) $user->id) {
                throw new RuntimeException(($lock['owner_name'] ?? 'Another user').' is currently editing this collection. Their pending changes are protected for 14 minutes after their last activity.');
            }
            if ($this->active($lock) && (int) ($lock['owner_id'] ?? 0) === (int) $user->id && ! $this->sameToken($lock)) {
                throw new RuntimeException('This collection is open in another tab. Continue in that tab, or wait for its editing lock to expire.');
            }
            $lock = $this->guardPendingSort($draft, $scope, $lock);
            $this->token = (string) Str::uuid();
            $lock = array_merge($lock, [
                'revision' => (int) ($lock['revision'] ?? $scopeRevision),
                'owner_id' => $user->id,
                'owner_name' => $user->name,
                'token_hash' => hash('sha256', $this->token),
                'last_activity_at' => now()->toISOString(),
                'expires_at' => now()->addSeconds(self::SECONDS)->toISOString(),
                'status' => null,
            ]);
            $this->writeLock($draft, $scope, $lock);

            return $draft;
        });
    }

    public function authorizeScope(ShopYourVibeDraft $draft, string $scope): void
    {
        if (! auth()->check()) {
            if (empty($this->locks($draft)[$scope])) {
                return;
            }
            throw new RuntimeException('This collection is open in another tab or session. Reopen it before editing.');
        }
        $user = $this->actor();
        $lock = $this->locks($draft)[$scope] ?? [];
        if (($lock['status'] ?? null) === 'pushing') {
            throw new RuntimeException('Wait for the Shopify push of this collection to finish before editing it.');
        }
        if ($this->active($lock)) {
            if ((int) ($lock['owner_id'] ?? 0) !== (int) $user->id || ! $this->sameToken($lock)) {
                throw new RuntimeException(($lock['owner_name'] ?? 'Another editor').' owns this editing lock. This collection is open in another tab. Continue in that tab, or wait for its editing lock to expire.');
            }
            $lock['last_activity_at'] = now()->toISOString();
            $lock['expires_at'] = now()->addSeconds(self::SECONDS)->toISOString();
            $this->writeLock($draft, $scope, $lock);

            return;
        }
        $lock = $this->guardPendingSort($draft, $scope, $lock);
        $this->token = (string) Str::uuid();
        $this->writeLock($draft, $scope, array_merge($lock, [
            'revision' => (int) ($lock['revision'] ?? 0),
            'owner_id' => $user->id,
            'owner_name' => $user->name,
            'token_hash' => hash('sha256', $this->token),
            'last_activity_at' => now()->toISOString(),
            'expires_at' => now()->addSeconds(self::SECONDS)->toISOString(),
            'status' => null,
        ]));
    }

    public function assertScopeRevision(ShopYourVibeDraft $draft, string $scope, int $scopeRevision, bool $adoptReturned = true): void
    {
        $userId = auth()->id();
        $lock = $this->locks($draft)[$scope] ?? [];
        $current = array_key_exists('revision', $lock) ? (int) $lock['revision'] : $scopeRevision;
        if ($current === $scopeRevision) {
            if (! array_key_exists('revision', $lock)) {
                $lock['revision'] = $scopeRevision;
                $this->writeLock($draft, $scope, $lock);
            }

            return;
        }
        $returned = $adoptReturned && ! $this->active($lock) && $userId && (int) ($lock['pending_owner_id'] ?? 0) === (int) $userId;
        if ($returned) {
            return;
        }
        throw new RuntimeException('This draft changed in another tab or session. Reopen it before editing.');
    }

    public function adoptedScopeRevision(ShopYourVibeDraft $draft, string $scope, int $known): ?int
    {
        $lock = $this->locks($draft)[$scope] ?? [];
        $current = array_key_exists('revision', $lock) ? (int) $lock['revision'] : $known;
        if ($current === $known || $this->active($lock)) {
            return null;
        }
        if ((int) ($lock['pending_owner_id'] ?? 0) !== (int) auth()->id()) {
            return null;
        }

        return $current;
    }

    public function bumpScope(ShopYourVibeDraft $draft, string $scope): void
    {
        $lock = $this->locks($draft)[$scope] ?? ['revision' => 0];
        $lock['revision'] = ((int) ($lock['revision'] ?? 0)) + 1;
        $this->writeLock($draft, $scope, $lock);
    }

    public function handoverScope(ShopYourVibeDraft $draft, string $scope, User $reviewer, User $submitter): void
    {
        $lock = $this->locks($draft)[$scope] ?? ['revision' => 0];
        $lock['owner_id'] = null;
        $lock['owner_name'] = null;
        $lock['token_hash'] = null;
        $lock['expires_at'] = now()->subSecond()->toISOString();
        $lock['status'] = null;
        $lock['pending_owner_id'] = $reviewer->id;
        $lock['pending_owner_name'] = $reviewer->name;
        $lock['review'] = [
            'reviewer_id' => $reviewer->id,
            'reviewer_name' => $reviewer->name,
            'submitted_by' => $submitter->id,
            'submitted_by_name' => $submitter->name,
            'submitted_revision' => (int) ($lock['revision'] ?? 0),
            'submitted_at' => now()->toISOString(),
        ];
        $this->writeLock($draft, $scope, $lock);
    }

    public function returnScope(ShopYourVibeDraft $draft, string $scope, User $reviewer, string $feedback): void
    {
        $lock = $this->locks($draft)[$scope] ?? ['revision' => 0];
        $submitterId = $lock['review']['submitted_by'] ?? null;
        $submitterName = $lock['review']['submitted_by_name'] ?? 'The original sorter';
        $lock['owner_id'] = null;
        $lock['owner_name'] = null;
        $lock['token_hash'] = null;
        $lock['expires_at'] = now()->subSecond()->toISOString();
        $lock['status'] = null;
        $lock['pending_owner_id'] = $submitterId;
        $lock['pending_owner_name'] = $submitterName;
        $lock['revision'] = ((int) ($lock['revision'] ?? 0)) + 1;
        $lock['review'] = [
            'reviewer_id' => $lock['review']['reviewer_id'] ?? $reviewer->id,
            'reviewer_name' => $lock['review']['reviewer_name'] ?? $reviewer->name,
            'submitted_by' => $submitterId,
            'submitted_by_name' => $submitterName,
            'submitted_revision' => $lock['review']['submitted_revision'] ?? (int) ($lock['revision'] ?? 0),
            'submitted_at' => $lock['review']['submitted_at'] ?? null,
            'changes_requested_by' => $reviewer->id,
            'changes_requested_by_name' => $reviewer->name,
            'changes_requested_at' => now()->toISOString(),
            'changes_requested_feedback' => $feedback,
            'returned' => true,
        ];
        $this->writeLock($draft, $scope, $lock);
    }

    /** @param array<string, mixed> $details */
    public function recordScope(ShopYourVibeDraft $draft, string $scope, string $type, array $details): void
    {
        $user = $this->actor();
        $lock = $this->locks($draft)[$scope] ?? ['revision' => 0];
        $lock['pending_owner_id'] = $draft->pending ? $user->id : ($lock['pending_owner_id'] ?? null);
        $lock['pending_owner_name'] = $draft->pending ? $user->name : ($lock['pending_owner_name'] ?? null);
        $lock['changed_at'] = now()->toISOString();
        $changes = array_values(array_filter($lock['changes'] ?? [], fn ($change) => ($change['type'] ?? null) !== $type || ($change['target'] ?? null) !== ($details['collection_gid'] ?? $scope)));
        $changes[] = ['editor_id' => $user->id, 'editor_name' => $user->name, 'type' => $type, 'target' => $details['collection_gid'] ?? $scope, 'at' => now()->toISOString(), 'details' => $details];
        $lock['changes'] = $changes;
        $this->writeLock($draft, $scope, $lock);
    }

    /** @return array<string, mixed> */
    public function ticketScope(ShopYourVibeDraft $draft, string $scope, string $gid): array
    {
        $user = $this->actor();
        $lock = $this->locks($draft)[$scope] ?? [];
        $slice = $draft->desired['collections'][$gid]['products'] ?? [];
        $ticket = [
            'id' => (string) Str::uuid(),
            'actor_id' => $user->id,
            'scope' => $scope,
            'scope_gid' => $gid,
            'revision' => (int) ($lock['revision'] ?? 0),
            'reviewed_revision' => (int) ($lock['revision'] ?? 0),
            'desired_hash' => hash('sha256', json_encode(array_column(is_array($slice) ? $slice : [], 'id'))),
            'kind' => 'push',
            'expires_at' => $lock['expires_at'] ?? now()->addSeconds(self::SECONDS)->toISOString(),
            'token_hash' => $lock['token_hash'] ?? null,
        ];
        $lock['status'] = 'pushing';
        $lock['push'] = $ticket;
        $this->writeLock($draft, $scope, $lock);

        return $ticket;
    }

    public function finishScopePush(ShopYourVibeDraft $draft, string $scope, bool $failed): void
    {
        $lock = $this->locks($draft)[$scope] ?? [];
        if ($failed) {
            $lock['status'] = null;
            $lock['push'] = null;
        } else {
            $lock = $this->withoutPublishedHold($lock);
        }
        $this->writeLock($draft, $scope, $lock);
    }

    /**
     * A published collection has no unpublished order left to protect.
     * The approval hold is cleared so another editor can sort it.
     *
     * @param  array<string, mixed>  $lock
     * @return array<string, mixed>
     */
    public function guardPendingSort(ShopYourVibeDraft $draft, string $scope, array $lock): array
    {
        $pendingOwner = $lock['pending_owner_id'] ?? null;
        $changesRemain = $this->sortChangesRemain($draft, $scope);
        if ($pendingOwner && (int) $pendingOwner !== (int) auth()->id() && $changesRemain) {
            throw new RuntimeException(($lock['pending_owner_name'] ?? 'Another editor').' has pending changes in this collection.');
        }
        if (! $changesRemain) {
            return $this->withoutPublishedHold($lock);
        }

        return $lock;
    }

    public function discardScope(int $id, string $gid, int $scopeRevision, string $reason): ShopYourVibeDraft
    {
        if (mb_strlen(preg_replace('/[^\p{L}\p{N}]/u', '', $reason)) < 10 || mb_strlen($reason) > 4000) {
            throw ValidationException::withMessages(['discardReason' => 'Enter a reason with at least 10 letters or numbers (maximum 4000 characters).']);
        }

        $preview = ShopYourVibeDraft::query()->findOrFail($id);
        $scope = self::sortScope($gid);
        $this->assertScopeRevision($preview, $scope, $scopeRevision, false);
        $this->guardSortDiscard($preview, $gid);
        $live = app(ShopYourVibeShopify::class)->collection($gid);
        $this->assertCompleteShopifyOrder($gid, $live);

        return DB::transaction(function () use ($id, $gid, $scope, $scopeRevision, $reason, $live) {
            $user = $this->actor();
            $draft = ShopYourVibeDraft::lockForUpdate()->findOrFail($id);
            $this->assertScopeRevision($draft, $scope, $scopeRevision, false);
            $this->guardSortDiscard($draft, $gid);
            $sorting = app(ShopYourVibeSorting::class);
            $sorting->releaseUnpublished($draft, $user->id, $gid);
            $desired = $draft->desired;
            $snapshot = $draft->snapshot ?? [];
            $desired['collections'][$gid] = $this->withShopifyOrder($desired['collections'][$gid] ?? [], $live);
            $snapshot['collections'][$gid] = $this->withShopifyOrder($snapshot['collections'][$gid] ?? [], $live);
            $lock = $this->locks($draft)[$scope] ?? [];
            unset($lock['owner_id'], $lock['token_hash'], $lock['pending_owner_id'], $lock['review']);
            $lock['owner_name'] = null;
            $lock['pending_owner_name'] = null;
            $lock['expires_at'] = now()->subSecond()->toISOString();
            $lock['status'] = null;
            $lock['revision'] = ((int) ($lock['revision'] ?? $scopeRevision)) + 1;
            $state = $draft->editing_state ?? [];
            $prompt = $state['membership_prompt'] ?? null;
            if (is_array($prompt) && ($prompt['gid'] ?? null) === $gid) {
                $state['membership_prompt'] = null;
            }
            $state['locks'][$scope] = $lock;
            $pending = app(ShopYourVibeWorkflow::class)->different($snapshot, $desired);
            $entry = ChangeLog::create(['changed_by' => $user->id, 'source' => 'shop_your_vibe_discard',
                'model_type' => ShopYourVibeDraft::class, 'model_id' => $draft->id, 'field' => 'collection_sort',
                'old_value' => json_encode(['scope' => $scope, 'collection_gid' => $gid], JSON_THROW_ON_ERROR),
                'new_value' => json_encode(['reason' => $reason, 'discarded_by_id' => $user->id, 'discarded_by_name' => $user->name,
                    'at' => now()->toISOString(), 'result' => 'loaded_shopify_order', 'scope' => $scope,
                    'product_count' => count($live['products'])], JSON_THROW_ON_ERROR)]);
            $state['last_discard_id'] = $entry->id;
            $state['last_discard_by'] = $user->name;
            $draft->update([
                'desired' => $desired,
                'snapshot' => $snapshot,
                'pending' => $pending,
                'status' => $pending ? 'pending' : 'synced',
                'editing_state' => $state,
            ]);
            $fresh = $draft->fresh() ?? $draft;
            $sorting->replaceMembership(
                $fresh,
                $gid,
                array_column($live['products'], 'id'),
                (string) ($live['title'] ?? ''),
            );

            return $fresh;
        });
    }

    private function guardSortDiscard(ShopYourVibeDraft $draft, string $gid): void
    {
        $user = $this->actor();
        $lock = $this->locks($draft)[self::sortScope($gid)] ?? [];
        if (! isset($draft->desired['collections'][$gid]) || ! is_array($draft->desired['collections'][$gid])) {
            throw new RuntimeException('Open this collection’s products before discarding its order.');
        }
        if ($this->active($lock) && (int) ($lock['owner_id'] ?? 0) !== (int) $user->id) {
            throw new RuntimeException('Discard blocked: '.($lock['owner_name'] ?? 'Another editor').' still has an active editing lock. Administrators cannot bypass it.');
        }
        $reviewerId = $lock['review']['reviewer_id'] ?? null;
        if ($reviewerId && (int) $reviewerId !== (int) $user->id) {
            throw new RuntimeException('Only the assigned reviewer can discard a submitted sorting revision.');
        }
    }

    /** @param array<string, mixed> $live */
    private function assertCompleteShopifyOrder(string $gid, array $live): void
    {
        $products = $live['products'] ?? null;
        if (! is_array($products) || ! array_is_list($products)) {
            throw new RuntimeException('Shopify returned incomplete pagination for '.$gid.'. Sorting was not discarded.');
        }
        $seen = [];
        foreach ($products as $product) {
            $id = is_array($product) ? ($product['id'] ?? null) : null;
            if (! is_string($id) || ! preg_match('~^gid://shopify/Product/\d+$~', $id)) {
                throw new RuntimeException('Shopify returned incomplete pagination for '.$gid.'. Sorting was not discarded.');
            }
            if (isset($seen[$id])) {
                throw new RuntimeException('Shopify membership for '.$gid.' changed while it was being read. Sorting was not discarded.');
            }
            $seen[$id] = true;
        }
        if ((int) ($live['product_count'] ?? -1) !== count($products)) {
            throw new RuntimeException('Shopify returned incomplete pagination for '.$gid.'. Sorting was not discarded.');
        }
    }

    /**
     * @param  array<string, mixed>  $collection
     * @param  array<string, mixed>  $live
     * @return array<string, mixed>
     */
    private function withShopifyOrder(array $collection, array $live): array
    {
        $collection['gid'] = $live['gid'];
        $collection['title'] = $live['title'];
        $collection['handle'] = $live['handle'];
        $collection['sort'] = $live['sort'];
        $collection['retrieval_started_at'] = $live['retrieval_started_at'];
        $collection['manual_supported'] = $live['manual_supported'];
        $collection['membership_supported'] = $live['membership_supported'];
        $collection['detected_membership_tag'] = $live['detected_membership_tag'] ?? null;
        $collection['product_count'] = $live['product_count'];
        $collection['products'] = $live['products'];

        return $collection;
    }

    public function heartbeatScope(int $id, string $scope): array
    {
        return DB::transaction(function () use ($id, $scope) {
            $draft = ShopYourVibeDraft::lockForUpdate()->findOrFail($id);
            $this->authorizeScope($draft, $scope);

            return $this->viewScope($draft, $scope);
        });
    }

    /** @return array<string, mixed> */
    public function viewScope(ShopYourVibeDraft $draft, string $scope): array
    {
        $lock = $this->locks($draft)[$scope] ?? [];
        $active = $this->active($lock);
        $mine = (int) ($lock['owner_id'] ?? 0) === (int) auth()->id();
        $pendingOwner = $lock['pending_owner_id'] ?? null;
        $holdsChanges = $pendingOwner !== null && $this->sortChangesRemain($draft, $scope);
        $publishedHold = ! $active && ! $holdsChanges;

        return [
            'active' => $active,
            'mine' => $mine,
            'owner' => $publishedHold ? null : ($lock['owner_name'] ?? null),
            'pending' => $holdsChanges,
            'revision' => (int) ($lock['revision'] ?? 0),
            'pending_owner' => $holdsChanges ? ($lock['pending_owner_name'] ?? null) : null,
            'changed_at' => $lock['changed_at'] ?? null,
            'expires_at' => $publishedHold ? null : ($lock['expires_at'] ?? null),
            'seconds' => $active ? max(0, now()->diffInSeconds($lock['expires_at'], false)) : 0,
            'blocked' => ($active && ! $mine) || ($holdsChanges && (int) $pendingOwner !== (int) auth()->id()),
            'changes' => $lock['changes'] ?? [],
            'scope' => $scope,
            'gid' => str_starts_with($scope, 'sort:') ? substr($scope, 5) : null,
            'title' => str_starts_with($scope, 'sort:') ? (string) ($draft->desired['collections'][substr($scope, 5)]['title'] ?? substr($scope, 5)) : null,
            'legacy_unassigned' => false,
            'last_discard_id' => $draft->editing_state['last_discard_id'] ?? null,
            'last_discard_by' => $draft->editing_state['last_discard_by'] ?? null,
        ];
    }

    /** @return array<string, array<string, mixed>> */
    public function scopeViews(ShopYourVibeDraft $draft): array
    {
        $views = [];
        foreach (array_keys($draft->desired['collections'] ?? []) as $gid) {
            if (is_string($gid) && $gid !== '') {
                $views[$gid] = $this->viewScope($draft, self::sortScope($gid));
            }
        }

        return $views;
    }

    public function viewFor(ShopYourVibeDraft $draft, ?string $gid): array
    {
        $parent = $this->view($draft);
        if (! is_string($gid) || $gid === '' || ! isset(($draft->desired['collections'] ?? [])[$gid])) {
            return $parent;
        }
        $scope = $this->viewScope($draft, self::sortScope($gid));
        $scopeIsIndependent = ($scope['active'] ?? false) || ($scope['pending'] ?? false) || ($scope['changes'] ?? []) !== [];
        if (! $scopeIsIndependent) {
            return $parent;
        }

        return $scope;
    }

    /** @return array<string, mixed> */
    private function locks(ShopYourVibeDraft $draft): array
    {
        $locks = $draft->editing_state['locks'] ?? [];

        return is_array($locks) ? $locks : [];
    }

    /**
     * @param  array<string, mixed>  $lock
     * @return array<string, mixed>
     */
    private function withoutPublishedHold(array $lock): array
    {
        unset($lock['owner_id'], $lock['token_hash'], $lock['pending_owner_id']);
        $lock['owner_name'] = null;
        $lock['pending_owner_name'] = null;
        $lock['expires_at'] = now()->subSecond()->toISOString();
        $lock['status'] = null;
        $lock['push'] = null;

        return $lock;
    }

    private function sortChangesRemain(ShopYourVibeDraft $draft, string $scope): bool
    {
        if (! str_starts_with($scope, 'sort:')) {
            return true;
        }
        $gid = substr($scope, 5);
        $desired = array_column($draft->desired['collections'][$gid]['products'] ?? [], 'id');
        $snapshot = array_column($draft->snapshot['collections'][$gid]['products'] ?? [], 'id');
        if ($desired !== $snapshot) {
            return true;
        }

        return SyvCollectionSortState::query()
            ->where('draft_id', $draft->id)
            ->where('collection_gid', $gid)
            ->where('explicit', true)
            ->where('status', '!=', SyvCollectionSortState::PUBLISHED)
            ->exists();
    }

    /** @param array<string, mixed> $lock */
    private function writeLock(ShopYourVibeDraft $draft, string $scope, array $lock): void
    {
        $state = $draft->editing_state ?? [];
        $state['locks'][$scope] = $lock;
        $draft->editing_state = $state;
        $draft->save();
    }

    /** @param array<string, mixed> $lock */
    private function sameToken(array $lock): bool
    {
        $hash = $lock['token_hash'] ?? null;

        return is_string($this->token) && $this->token !== '' && is_string($hash) && $hash !== ''
            && hash_equals($hash, hash('sha256', $this->token));
    }

    public function view(ShopYourVibeDraft $draft): array
    {
        $state = $draft->editing_state ?? [];
        $active = $this->active($state);
        $mine = ($state['owner_id'] ?? null) === auth()->id();
        $pendingId = $state['pending_owner_id'] ?? null;
        $pendingName = is_string($state['pending_owner_name'] ?? null) && $state['pending_owner_name'] !== '' && $state['pending_owner_name'] !== 'Unknown legacy editor'
            ? $state['pending_owner_name']
            : null;
        if ($pendingName === null && $pendingId) {
            $pendingName = User::query()->whereKey($pendingId)->value('name');
        }
        $legacyUnassigned = (bool) $draft->pending && $pendingId === null && $pendingName === null;

        return ['active' => $active, 'mine' => $mine, 'owner' => $state['owner_name'] ?? null,
            'pending' => $draft->pending, 'revision' => $draft->revision,
            'pending_owner' => $pendingName, 'legacy_unassigned' => $legacyUnassigned,
            'changed_at' => $state['changed_at'] ?? null, 'expires_at' => $state['expires_at'] ?? null,
            'seconds' => $active ? max(0, now()->diffInSeconds($state['expires_at'], false)) : 0,
            'blocked' => ($active && ! $mine) || ($draft->pending && ($state['pending_owner_id'] ?? null) !== null && ($state['pending_owner_id'] ?? null) !== auth()->id()),
            'changes' => $state['changes'] ?? [], 'last_discard_id' => $state['last_discard_id'] ?? null,
            'last_discard_by' => $state['last_discard_by'] ?? null];
    }
}
