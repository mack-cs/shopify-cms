<?php

namespace App\Services;

use App\Enums\RolesEnum;
use App\Jobs\InventorySyncJob;
use App\Models\InventoryAdjustmentRequest;
use App\Models\InventoryPushCancellation;
use App\Models\InventoryWriteIntent;
use App\Models\User;
use App\Models\Variant;
use Illuminate\Database\Connection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final class InventoryPendingPushCancellation
{
    public const LOCK_SECONDS = 1920;

    public static function lockKey(int $variantId): string
    {
        return 'inventory-push-admin:'.$variantId;
    }

    public static function isSuperAdmin(?User $user): bool
    {
        return $user !== null && $user->is_active && $user->hasRole(RolesEnum::SuperAdmin);
    }

    /** Preview is local/read-only. Shared multi-SKU approvals are included in full and disclosed. */
    public function preview(array $variantIds, int $actorId): array
    {
        abort_unless(self::isSuperAdmin(User::find($actorId)), 403);
        $variantIds = array_values(array_unique(array_map('intval', $variantIds)));
        if ($variantIds === []) {
            throw ValidationException::withMessages(['reason' => 'No inventory SKUs were selected.']);
        }
        do {
            $before = $variantIds;
            $requests = InventoryAdjustmentRequest::query()->with('items')
                ->whereIn('status', ['PENDING_APPROVAL', 'APPROVED', 'FAILED'])
                ->whereHas('items', fn ($q) => $q->whereIn('variant_id', $variantIds))->orderBy('id')->get();
            $variantIds = array_values(array_unique(array_merge($variantIds, $requests->flatMap(fn ($r) => $r->items->pluck('variant_id'))->map(fn ($id) => (int) $id)->all())));
            sort($variantIds);
            if (count($variantIds) > 1000) {
                throw ValidationException::withMessages(['reason' => 'Linked approvals affect more than 1,000 SKUs. Narrow the selection or investigate the linked requests separately.']);
            }
        } while ($before !== $variantIds);
        $variants = Variant::query()->whereIn('id', $variantIds)->orderBy('id')->get();
        if ($variants->count() !== count($variantIds)) {
            throw ValidationException::withMessages(['reason' => 'A selected SKU no longer exists.']);
        }
        $intents = InventoryWriteIntent::query()->whereIn('variant_id', $variantIds)->where('status', 'started')->orderBy('id')->get();
        $jobs = $this->jobs(false, $variantIds);
        $failed = $this->jobs(true, $variantIds);
        $state = [
            'variants' => $variants->map(fn ($v) => $v->only(['id', 'sku', 'shopify_id', 'shopify_inventory_item_id', 'inventory_write_version',
                'inventory_local_dirty', 'inventory_sync_error', 'inventory_sync_batch_id']))->all(),
            'requests' => $requests->map(fn ($r) => ['id' => $r->id, 'status' => $r->status, 'batch' => $r->sync_batch_id,
                'shopify_result' => $r->shopify_result, 'applied_at' => $r->applied_at?->toIso8601String(),
                'reviewed_by' => $r->reviewed_by,
                'items' => $r->items->map(fn ($i) => $i->only(['id', 'variant_id', 'requested_on_hand_quantity', 'requested_inventory_tracked']))->all()])->all(),
            'intents' => $intents->map(fn ($i) => $i->only(['id', 'variant_id', 'intent_key', 'payload', 'status', 'error', 'updated_at']))->all(),
            'jobs' => $jobs, 'failed_jobs' => $failed,
        ];

        return $state + ['variant_ids' => $variantIds, 'fingerprint' => hash('sha256', json_encode($state, JSON_THROW_ON_ERROR))];
    }

    public function cancel(array $variantIds, int $actorId, string $reason, string $fingerprint): array
    {
        abort_unless(self::isSuperAdmin(User::find($actorId)), 403);
        if (trim($reason) === '') {
            throw ValidationException::withMessages(['reason' => 'Explain why these pending pushes are being discarded.']);
        }
        $plan = $this->preview($variantIds, $actorId);
        $this->unchanged($plan, $fingerprint);
        $locks = [];
        try {
            foreach ($plan['variant_ids'] as $id) {
                $lock = Cache::lock(self::lockKey($id), self::LOCK_SECONDS);
                if (! $lock->get()) {
                    throw ValidationException::withMessages(['reason' => 'An inventory push is running for an affected SKU. Wait for it to finish; nothing was discarded.']);
                }
                $locks[] = $lock;
            }
            $this->assertNotReserved($plan);
            $snapshots = [];
            foreach (Variant::query()->whereIn('id', $plan['variant_ids'])->orderBy('id')->get() as $variant) {
                try {
                    $snapshots[$variant->id] = app(ProductInventorySyncService::class)->readForPushCancellation($variant);
                } catch (\Throwable $error) {
                    throw ValidationException::withMessages(['reason' => 'Shopify could not be read safely. Nothing was discarded. '.$error->getMessage()]);
                }
                $locations = collect($snapshots[$variant->id]['locations'])->pluck('location.id')->all();
                foreach (collect($plan['intents'])->where('variant_id', $variant->id) as $intent) {
                    if (! in_array(data_get($intent, 'payload.location'), $locations, true)) {
                        throw ValidationException::withMessages(['reason' => 'An unresolved intent references a Shopify location that was not returned. Nothing was discarded.']);
                    }
                }
            }

            return DB::transaction(function () use ($plan, $actorId, $reason, $fingerprint, $snapshots, $locks): array {
                abort_unless(self::isSuperAdmin(User::find($actorId)), 403);
                foreach ($locks as $lock) {
                    if (! $lock->isOwnedByCurrentProcess()) {
                        throw ValidationException::withMessages(['reason' => 'The inventory lock expired while Shopify was being read. Nothing was discarded; review again.']);
                    }
                }
                $variants = Variant::query()->whereIn('id', $plan['variant_ids'])->orderBy('id')->lockForUpdate()->get();
                InventoryAdjustmentRequest::query()->whereIn('id', array_column($plan['requests'], 'id'))->orderBy('id')->lockForUpdate()->get();
                InventoryWriteIntent::query()->whereIn('variant_id', $plan['variant_ids'])->orderBy('id')->lockForUpdate()->get();
                $queue = $this->queueConnection();
                $queue->table(config('queue.connections.inventory-sync.table', 'jobs'))->whereIn('id', array_column($plan['jobs'], 'id'))->lockForUpdate()->get();
                $current = $this->preview($plan['variant_ids'], $actorId);
                $this->unchanged($current, $fingerprint);
                $this->assertNotReserved($current);
                $deleted = [];
                foreach ($plan['jobs'] as $job) {
                    // Preserve the shared job for unselected SKUs; the worker filters cancelled variants.
                    if (array_diff($job['variant_ids'], $plan['variant_ids']) === []) {
                        $queue->table(config('queue.connections.inventory-sync.table', 'jobs'))->where('id', $job['id'])->whereNull('reserved_at')->delete();
                        $deleted[] = $job['id'];
                    }
                }
                $auditIds = [];
                foreach ($variants as $variant) {
                    $requests = collect($plan['requests'])->filter(fn ($r) => collect($r['items'])->contains('variant_id', $variant->id))->values();
                    $jobs = collect(array_merge($plan['jobs'], $plan['failed_jobs']))->filter(fn ($j) => in_array($variant->id, $j['variant_ids'], true));
                    $batches = $requests->pluck('batch')->merge($jobs->pluck('batch'))->push($variant->inventory_sync_batch_id)->filter()->unique()->values()->all();
                    $intents = collect($plan['intents'])->where('variant_id', $variant->id)->values()->all();
                    $audit = InventoryPushCancellation::create([
                        'variant_id' => $variant->id, 'actor_id' => $actorId, 'reason' => trim($reason), 'batch_ids' => $batches,
                        'job_uuids' => $jobs->pluck('uuid')->filter()->unique()->values()->all(),
                        'evidence' => ['previous_variant' => $variant->getAttributes(), 'requests' => $requests->all(), 'intents' => $intents,
                            'jobs' => $jobs->values()->all(), 'deleted_pending_job_ids' => $deleted, 'shopify' => $snapshots[$variant->id],
                            'previous_remote_outcome' => 'Not inferred. Super Admin accepted current Shopify state as authoritative.'],
                    ]);
                    $auditIds[] = $audit->id;
                    InventoryWriteIntent::query()->whereIn('id', array_column($intents, 'id'))->where('status', 'started')
                        ->update(['status' => 'cancelled', 'error' => 'Discarded after reading current Shopify state; cancellation audit '.$audit->id, 'updated_at' => now()]);
                    Variant::withoutEvents(fn () => $variant->forceFill($snapshots[$variant->id]['updates'] + [
                        'inventory_write_version' => (int) $variant->inventory_write_version + 1,
                        'inventory_local_dirty' => false, 'inventory_sync_error' => null, 'inventory_sync_batch_id' => null,
                        'inventory_last_synced_at' => now(),
                    ])->save());
                }
                foreach (InventoryAdjustmentRequest::query()->whereIn('id', array_column($plan['requests'], 'id'))->get() as $request) {
                    $request->update(['status' => InventoryAdjustmentRequest::STATUS_CANCELLED,
                        'shopify_result' => json_encode(['cancelled_by' => $actorId, 'reason' => trim($reason), 'cancellation_audit_ids' => $auditIds,
                            'cancelled_at' => now()->toIso8601String(), 'shopify_inventory_changed' => false], JSON_THROW_ON_ERROR)]);
                }

                return ['cancelled_requests' => count($plan['requests']), 'deleted_jobs' => count($deleted), 'refreshed_skus' => count($variants), 'audit_ids' => $auditIds];
            });
        } finally {
            foreach (array_reverse($locks) as $lock) {
                $lock->release();
            }
        }
    }

    private function unchanged(array $plan, string $fingerprint): void
    {
        if (! hash_equals($plan['fingerprint'], $fingerprint)) {
            throw ValidationException::withMessages(['reason' => 'Pending inventory work changed since the preview. Close and reopen the action to review it again.']);
        }
    }

    private function assertNotReserved(array $plan): void
    {
        if (collect($plan['jobs'])->contains(fn ($j) => $j['reserved_at'] !== null)) {
            throw ValidationException::withMessages(['reason' => 'An affected queue job is reserved/running. It cannot be safely deleted here. Wait for the worker to finish or investigate the stale reservation.']);
        }
    }

    private function queueConnection(): Connection
    {
        $connection = DB::connection(config('queue.connections.inventory-sync.connection'));
        if (config('queue.connections.inventory-sync.driver') !== 'database' || $connection !== DB::connection()) {
            throw ValidationException::withMessages(['reason' => 'This action requires the inventory-sync database queue on the application database connection.']);
        }

        return $connection;
    }

    /** Decode only known scalar job properties, never unserialize executable queue content. */
    private function jobs(bool $failed, array $variantIds): array
    {
        $connection = $failed ? DB::connection(config('queue.failed.database')) : $this->queueConnection();
        $table = config($failed ? 'queue.failed.table' : 'queue.connections.inventory-sync.table', $failed ? 'failed_jobs' : 'jobs');
        $result = [];
        foreach ($connection->table($table)->where('queue', 'inventory-sync')->orderBy('id')->get() as $row) {
            $payload = json_decode($row->payload, true) ?: [];
            if (($payload['displayName'] ?? '') !== InventorySyncJob::class) {
                continue;
            }
            $command = (string) data_get($payload, 'data.command');
            if (preg_match('/"mode";s:\d+:"refresh";/', $command)) {
                continue;
            }
            if (! preg_match('/"variantIds";a:(\d+):\{([^}]*)\}/', $command, $array)
                || ! preg_match('/"syncBatchId";(?:s:\d+:"([^"]*)";|N;)/', $command, $batch)) {
                throw ValidationException::withMessages(['reason' => 'An inventory push payload cannot be inspected safely. Nothing was discarded.']);
            }
            preg_match_all('/i:\d+;(?:i:(\d+);|s:\d+:"(\d+)";)/', $array[2], $ids, PREG_SET_ORDER);
            if (count($ids) !== (int) $array[1]) {
                throw ValidationException::withMessages(['reason' => 'An inventory push has unsupported variant IDs. Nothing was discarded.']);
            }
            $ids = array_map(fn ($match) => (int) ($match[1] !== '' ? $match[1] : $match[2]), $ids);
            if (array_intersect($ids, $variantIds) !== []) {
                $result[] = ['id' => $row->id, 'uuid' => $row->uuid ?? $payload['uuid'] ?? null,
                    'variant_ids' => $ids, 'batch' => $batch[1] ?? null, 'reserved_at' => $row->reserved_at ?? null,
                    'payload_hash' => hash('sha256', $row->payload), 'failed' => $failed];
            }
        }

        return $result;
    }
}
