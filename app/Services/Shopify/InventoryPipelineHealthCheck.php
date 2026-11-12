<?php

namespace App\Services\Shopify;

use App\Filament\Resources\ShopifyStackInventoryReservationResource;
use App\Models\EngravedBeadFamily;
use App\Models\EngravedBeadOrderLine;
use App\Models\EngravedBeadSellabilityOperation;
use App\Models\InventoryOperationAudit;
use App\Models\InventoryPipelineIncident;
use App\Models\NewProductDraft;
use App\Models\ShopifyFulfillment;
use App\Models\ShopifyStackInventoryMovement;
use App\Models\ShopifyStackInventoryReservation;
use App\Models\ShopifyStackOrderEvent;
use App\Models\Variant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

final class InventoryPipelineHealthCheck
{
    public function __construct(private readonly InventoryPipelineNotifications $notifications) {}

    public function scan(bool $liveAudit = false): void
    {
        if (! config('inventory_monitor.enabled')) {
            return;
        }
        foreach ([ShopifyStackOrderEvent::class, ShopifyFulfillment::class, ShopifyStackInventoryReservation::class,
            ShopifyStackInventoryMovement::class] as $class) {
            foreach ($class::query()->cursor() as $record) {
                $this->recordHealth($record);
            }
        }
        foreach (InventoryOperationAudit::query()->where(function ($query): void {
            $query->where('workflow', 'like', 'stack_%')->orWhere('workflow', 'like', 'engraved_bead%');
        })->cursor() as $audit) {
            $this->recordChanged($audit);
        }
        foreach (EngravedBeadOrderLine::query()->cursor() as $line) {
            $this->recordChanged($line);
        }
        foreach (EngravedBeadFamily::query()->cursor() as $family) {
            $this->recordChanged($family);
        }
        foreach (EngravedBeadSellabilityOperation::query()->cursor() as $operation) {
            $this->recordChanged($operation);
        }
        $this->snapshots();
        $this->queues();
        $this->failedJobs();
        if ($liveAudit) {
            // Reuse the existing read-only Shopify leftover audit instead of sending a second daily email.
            $report = app(StackReservationFulfillmentAuditService::class)->report(strict: true);
            $issues = [];
            foreach ($report['rows'] as $row) {
                $issues[$row['reservation_id']] = $row;
            }
            foreach (ShopifyStackInventoryReservation::query()->cursor() as $reservation) {
                $row = $issues[$reservation->id] ?? null;
                $this->notifications->observe('live_leftover_reservation', $reservation->getTable(), $reservation->id,
                    $row !== null && $row['issue'] !== StackReservationFulfillmentAuditService::ISSUE_OPEN,
                    $this->details($reservation) + ['error_message' => $row['issue'] ?? null]);
            }
        }
    }

    /** Called after committed model changes as well as by the scheduler fallback. */
    public function recordChanged(Model $record): void
    {
        if (! config('inventory_monitor.enabled')) {
            return;
        }
        if ($record instanceof InventoryOperationAudit) {
            if (str_starts_with($record->workflow, 'stack_') || str_starts_with($record->workflow, 'engraved_bead')) {
                $this->notifications->observe('inventory_adjustment_failed_or_unconfirmed', $record->getTable(), $record->id,
                    $record->status === 'failed', $this->details($record) + [
                        'remote_outcome' => 'A failed response does not prove Shopify rejected the mutation. Check the movement and Shopify confirmation.',
                    ]);
            }
        } elseif ($record instanceof EngravedBeadOrderLine) {
            $this->notifications->observe('engraved_bead_configuration', $record->getTable(), $record->id,
                in_array($record->configuration, ['blocked', 'invalid'], true), $this->details($record));
        } elseif ($record instanceof EngravedBeadFamily) {
            $this->notifications->observe('engraved_bead_reconciliation', $record->getTable(), $record->id,
                filled($record->sync_error) || in_array($record->status, ['reconciliation_required', 'sync_failed'], true),
                array_replace($this->details($record), ['error_message' => $record->sync_error]));
        } elseif ($record instanceof EngravedBeadSellabilityOperation) {
            $this->notifications->observe('engraved_bead_operation_unconfirmed', $record->getTable(), $record->id,
                $record->status === 'uncertain' || ($record->status !== 'confirmed'
                    && $record->created_at->lte(now()->subMinutes(max(1, config('inventory_monitor.processing_minutes'))))), $this->details($record));
        } else {
            $this->recordHealth($record);
        }
    }

    private function recordHealth(Model $record): void
    {
        $status = (string) ($record->processing_status ?? $record->status);
        $healthy = in_array($status, ['completed', 'released', 'written_off', 'ignored'], true);
        $failed = $status === 'failed' || (! $healthy && filled($record->error_message));
        $details = $this->details($record);
        // An in-progress retry is not recovery. Wait for a confirmed terminal state.
        $reservationRecovered = $record instanceof ShopifyStackInventoryReservation && $status === 'pending'
            && ! $record->error_message && $record->reserved_quantity > 0
            && ! $record->movements()->where('status', '!=', 'completed')->exists();
        if ($failed || $healthy || $reservationRecovered) {
            $this->notifications->observe('pipeline_record_failed', $record->getTable(), $record->id, $failed, $details);
        }
        $processing = $status === 'processing';
        $minutes = max(1, (int) config('inventory_monitor.'.($processing ? 'processing_minutes' : 'pending_minutes')));
        $since = $processing ? ($record->processing_started_at ?? $record->updated_at) : $record->created_at;
        $stale = ! $healthy && $since->lte(now()->subMinutes($minutes));
        $this->notifications->observe('pipeline_record_stale', $record->getTable(), $record->id, $stale,
            $details + ['threshold_minutes' => $minutes, 'waiting_since' => $since->toIso8601String()]);
    }

    private function snapshots(): void
    {
        $seenOrders = [];
        // Arrival order is not Shopify event order. Ignore stale updates that the processor already rejected.
        $events = ShopifyStackOrderEvent::query()->where(fn ($query) => $query->whereNull('error_message')
            ->orWhere('error_message', 'not like', 'Ignored because a newer Shopify order event%'))
            ->orderByDesc('shopify_updated_at')->orderByDesc('id');
        foreach ($events->cursor() as $event) {
            if (isset($seenOrders[$event->shopify_order_id])) {
                continue;
            }
            $seenOrders[$event->shopify_order_id] = true;
            if ($event->created_at->gt(now()->subMinutes(max(1, config('inventory_monitor.snapshot_grace_minutes'))))) {
                continue;
            }
            foreach ((array) data_get($event->payload, 'line_items', []) as $line) {
                if (! is_array($line) || (int) ($line['current_quantity'] ?? $line['quantity'] ?? 0) <= 0) {
                    continue;
                }
                $lineId = $this->gid('LineItem', $line['admin_graphql_api_id'] ?? $line['id'] ?? '');
                if ($lineId === '') {
                    continue;
                }
                $variantId = trim((string) ($line['variant_id'] ?? ''));
                $variant = $variantId === '' ? null : Variant::query()->with('product')->whereIn('shopify_id',
                    [$variantId, $this->gid('ProductVariant', $variantId)])->first();
                $draft = NewProductDraft::configuredStackMatching($variant, $line);
                // Monitoring can flag absent/empty configuration even when runtime classification skips it.
                $productId = trim((string) ($variant?->product?->shopify_id ?? $line['product_id'] ?? ''));
                $identifiedDraft = $draft ?? ($productId === '' ? null : NewProductDraft::query()
                    ->whereIn('shopify_id', [$productId, $this->gid('Product', $productId)])->first());
                $rows = ShopifyStackInventoryReservation::query()->where('shopify_order_id', $event->shopify_order_id)
                    ->where('shopify_order_line_item_id', $lineId)->get();
                $identity = $event->shopify_order_id.'|'.$lineId;
                $knownStack = $draft !== null || $identifiedDraft?->isBundleOrStack() || $variant?->product?->is_bundle;
                $this->notifications->observe('stack_configuration_missing', 'order_line', $identity,
                    $knownStack && ! $identifiedDraft?->hasConfiguredStackComponents(), array_replace($this->details($event), [
                        'line_id' => $lineId, 'stack_skus' => [$line['sku'] ?? $variant?->sku],
                        'error_message' => 'Known stack has no usable component configuration. Verify product identity and draft configuration.',
                    ]));
                $draft ??= $identifiedDraft?->hasConfiguredStackComponents() ? $identifiedDraft : null;
                if (! $draft) {
                    // Do not infer recovery when catalog/configuration evidence disappeared.
                    continue;
                }
                $expected = array_values(array_unique(array_filter(array_map('intval', (array) $draft->bundle_product_ids), fn ($id) => $id > 0)));
                $actual = $rows->pluck('configured_component_product_id')->map(fn ($id) => (int) $id)->all();
                $missing = array_values(array_diff($expected, $actual));
                $intentionalSkip = $event->topic === 'orders/cancelled'
                    || str_starts_with((string) $event->error_message, 'Skipped reservation because live Shopify');
                $this->notifications->observe('incomplete_component_snapshot', 'order_line', $identity,
                    ! $intentionalSkip && $missing !== [], array_replace($this->details($event), [
                        'line_id' => $lineId, 'stack_skus' => [$line['sku'] ?? $variant?->sku ?? $draft->sku],
                        'expected_component_product_ids' => $expected, 'actual_component_product_ids' => $actual,
                        'missing_component_product_ids' => $missing,
                        'error_message' => 'Snapshot differs from current configured components. Verify configuration history before repair.',
                    ]));
            }
        }
    }

    private function queues(): void
    {
        // Inventory connections currently use the same database jobs table. Never consume or release jobs.
        $connection = DB::connection(config('queue.connections.inventory-ledger.connection'));
        $table = config('queue.connections.inventory-ledger.table', 'jobs');
        $cutoff = now()->subMinutes(max(1, config('inventory_monitor.queue_minutes')))->timestamp;
        foreach (config('inventory_monitor.queues', []) as $queue) {
            $oldest = $connection->table($table)->where('queue', $queue)->where(function ($query) use ($cutoff): void {
                $query->where('reserved_at', '<=', $cutoff)->orWhere(fn ($q) => $q->whereNull('reserved_at')->where('available_at', '<=', $cutoff));
            })->orderBy('id')->first();
            $target = $oldest ? $this->jobTarget(json_decode($oldest->payload, true) ?: []) : null;
            $this->notifications->observe('queue_not_processing', 'queue', $queue, $oldest !== null,
                array_replace($target ? $this->details($target) : [], ['queue' => $queue, 'job_id' => $oldest?->id, 'status' => $oldest?->reserved_at ? 'reserved/stuck' : 'waiting',
                    'threshold_minutes' => config('inventory_monitor.queue_minutes'),
                    'error_message' => 'Inspect worker supervision, queue connection, available jobs and worker timeouts. Do not retry inventory blindly.']));
        }
    }

    public function exhausted(array $payload, string $queue, string $error, int $attempts = 0): void
    {
        if (! config('inventory_monitor.enabled') || ! in_array($queue, config('inventory_monitor.queues', []), true)) {
            return;
        }
        $uuid = $payload['uuid'] ?? hash('sha256', json_encode($payload));
        $target = $this->jobTarget($payload);
        $this->notifications->observe('job_retries_exhausted', 'job', $uuid, true,
            array_replace($target ? $this->details($target) : [], ['job_uuid' => $uuid, 'job' => $payload['displayName'] ?? 'Unknown',
                'queue' => $queue, 'error_message' => mb_substr($error, 0, 10000), 'retry_attempts' => $attempts,
                'status' => 'retries_exhausted', 'target_type' => $target?->getTable(), 'target_id' => $target?->id]));
    }

    public function completedJob(array $payload, string $queue): void
    {
        if (! config('inventory_monitor.enabled') || ! in_array($queue, config('inventory_monitor.queues', []), true)) {
            return;
        }
        $target = $this->jobTarget($payload);
        if ($target instanceof EngravedBeadFamily && ($target->status !== 'active' || $target->sync_error)) {
            return;
        }
        if ($target && ! $target instanceof EngravedBeadFamily
            && ! in_array($target->processing_status ?? $target->status, ['completed', 'ignored', 'released', 'written_off'], true)) {
            return;
        }
        $uuid = $payload['uuid'] ?? hash('sha256', json_encode($payload));
        $this->notifications->observe('job_retries_exhausted', 'job', $uuid, false,
            ['status' => 'job_completed', 'completed_at' => now()->toIso8601String()]);
    }

    private function failedJobs(): void
    {
        $connection = DB::connection(config('queue.failed.database'));
        $table = config('queue.failed.table', 'failed_jobs');
        if ($connection->getSchemaBuilder()->hasTable($table)) {
            foreach ($connection->table($table)->whereIn('queue', config('inventory_monitor.queues', []))->cursor() as $job) {
                $payload = json_decode($job->payload, true) ?: [];
                $known = InventoryPipelineIncident::query()->where('kind', 'job_retries_exhausted')
                    ->where('source_type', 'job')->where('source_id', $payload['uuid'] ?? $job->uuid)->first();
                if ($known?->resolved_at?->gte(Carbon::parse($job->failed_at))) {
                    continue;
                }
                $target = $this->jobTarget($payload);
                if ($target instanceof EngravedBeadFamily && $target->status === 'active' && ! $target->sync_error
                    && $target->last_reconciled_at?->gt(Carbon::parse($job->failed_at))) {
                    continue;
                }
                if ($target && in_array($target->processing_status ?? $target->status, ['completed', 'ignored', 'released', 'written_off'], true)) {
                    continue;
                }
                $this->exhausted($payload + ['uuid' => $job->uuid], $job->queue, $job->exception, (int) ($target?->attempts ?? 0));
            }
        }
        // Removing a failed_jobs row alone is not evidence of recovery.
        foreach (InventoryPipelineIncident::query()->where('kind', 'job_retries_exhausted')->whereNull('resolved_at')->cursor() as $incident) {
            $class = match ($incident->details['target_type'] ?? null) {
                'shopify_fulfillments' => ShopifyFulfillment::class,
                'shopify_stack_order_events' => ShopifyStackOrderEvent::class,
                'engraved_bead_families' => EngravedBeadFamily::class,
                default => null,
            };
            $target = $class ? $class::query()->find($incident->details['target_id']) : null;
            $recovered = $target instanceof EngravedBeadFamily
                ? $target->status === 'active' && ! $target->sync_error && $target->last_reconciled_at?->gt($incident->detected_at)
                : $target && in_array($target->processing_status ?? $target->status, ['completed', 'ignored'], true);
            if ($recovered) {
                $this->notifications->observe($incident->kind, $incident->source_type, $incident->source_id, false, $this->details($target));
            }
        }
    }

    private function jobTarget(array $payload): ?Model
    {
        // Read only scalar IDs from known Laravel job serialization; never unserialize queue content.
        [$class, $field] = match ($payload['displayName'] ?? '') {
            'App\\Jobs\\ProcessShopifyStackFulfillmentJob' => [ShopifyFulfillment::class, 'fulfillmentId'],
            'App\\Jobs\\ProcessShopifyStackOrderEventJob' => [ShopifyStackOrderEvent::class, 'eventId'],
            'App\\Jobs\\SynchronizeEngravedBeadFamilyJob' => [EngravedBeadFamily::class, 'familyId'],
            default => [null, null],
        };
        if ($class && preg_match('/"'.$field.'";i:(\d+);/', (string) data_get($payload, 'data.command'), $match)) {
            return $class::query()->find($match[1]);
        }

        return null;
    }

    private function details(Model $record): array
    {
        $reservation = $record instanceof ShopifyStackInventoryMovement ? $record->reservation : null;
        if ($record instanceof InventoryOperationAudit && $record->related_type === ShopifyStackInventoryMovement::class) {
            $reservation = ShopifyStackInventoryMovement::query()->find($record->related_id)?->reservation;
        }
        $orderId = $record->shopify_order_id ?? $reservation?->shopify_order_id;
        $orderIds = $orderId ? [$orderId, $this->gid('Order', $orderId)] : [];
        $rows = $orderId ? ShopifyStackInventoryReservation::query()->whereIn('shopify_order_id', $orderIds)->get() : collect();
        $event = $orderId ? ShopifyStackOrderEvent::query()->whereIn('shopify_order_id', $orderIds)->latest('id')->first() : null;
        $payloadSkus = collect((array) data_get($record->payload, 'line_items', []))->filter(fn ($line) => is_array($line)
            && NewProductDraft::configuredStackMatching(null, $line) !== null)->pluck('sku')->filter()->unique()->values()->all();

        return array_filter([
            'order_number' => $record->shopify_order_name ?? $reservation?->shopify_order_name ?? $rows->first()?->shopify_order_name
                ?? $event?->shopify_order_name ?? ($record instanceof ShopifyFulfillment ? $record->order?->name : null),
            'order_id' => $orderId, 'fulfillment_id' => $record->shopify_fulfillment_id,
            'stack_skus' => $rows->isNotEmpty() ? $rows->pluck('stack_sku')->filter()->unique()->values()->all() : $payloadSkus,
            'component_skus' => $rows->isNotEmpty() ? $rows->pluck('component_sku')->filter()->unique()->values()->all() : array_filter([$record->sku]),
            'status' => $record->processing_status ?? $record->status ?? $record->configuration,
            'retry_attempts' => $record->attempts ?? 0, 'error_message' => $record->error_message,
            'record_updated_at' => $record->updated_at?->toIso8601String(),
            'cms_url' => $orderId ? ShopifyStackInventoryReservationResource::getUrl('index',
                ['tableSearch' => $orderId], panel: 'admin') : null,
        ], fn ($value) => $value !== null);
    }

    private function gid(string $type, mixed $id): string
    {
        $id = trim((string) $id);

        return $id === '' || str_starts_with($id, 'gid://shopify/') ? $id : 'gid://shopify/'.$type.'/'.$id;
    }
}
