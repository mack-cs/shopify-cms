<?php

namespace App\Services\Shopify;

use App\Jobs\ProcessShopifyStackFulfillmentJob;
use App\Models\InventoryOperationAudit;
use App\Models\NewProductDraft;
use App\Models\Product;
use App\Models\ShopifyFulfillment;
use App\Models\ShopifyStackComponentDeduction;
use App\Models\ShopifyStackInventoryMovement;
use App\Models\ShopifyStackInventoryReservation;
use App\Models\ShopifyStackOrderEvent;
use App\Models\Variant;
use App\Services\EngravedBeads\EngravedBeadConfiguration;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/** Evidence collection only. Never calls a processor, mailer, dispatcher or Shopify gateway. */
final class FailedStackFulfillmentDiagnostic
{
    public const POTENTIAL = 'potential_safe_retry';

    public const RECONCILE = 'requires_inventory_reconciliation';

    public const MANUAL = 'requires_manual_investigation';

    public static function gid(string $type, mixed $value): string
    {
        $value = trim((string) $value);

        return $value === '' || str_starts_with($value, 'gid://shopify/') ? $value : "gid://shopify/{$type}/{$value}";
    }

    public static function aliases(string $type, mixed $value): array
    {
        $gid = self::gid($type, $value);

        return array_values(array_unique(array_filter([$gid, basename($gid)])));
    }

    public static function missingError(?string $error): bool
    {
        return preg_match('/(?:no|missing|incomplete)[^\r\n]*(?:component|reserv|snapshot)|(?:component|reserv|snapshot)[^\r\n]*(?:missing|incomplete|not found|could not be found|not exist|empty)/i', $error ?? '') === 1;
    }

    /** Must be called by the command inside its read-only database transaction(s). */
    public function report(?string $orderFilter = null, ?string $fulfillmentFilter = null): array
    {
        $failed = $this->queueEvidence(true);
        $queued = $this->queueEvidence(false);
        $targetIds = collect($failed['rows'])->pluck('target_id')->filter()->all();
        $candidates = ShopifyFulfillment::query()->where(fn ($q) => $q->where('processing_status', 'failed')->orWhereIn('id', $targetIds))
            ->orderBy('id')->cursor()->filter(fn ($f) => ($f->processing_status === 'failed' && self::missingError($f->error_message))
                || in_array($f->id, $targetIds))->collect();
        $byId = $candidates->keyBy('id');
        $groups = [];
        foreach ($candidates->groupBy(fn ($f) => self::gid('Fulfillment', $f->shopify_fulfillment_id)) as $canonical => $seeds) {
            if ($fulfillmentFilter !== null && $canonical !== self::gid('Fulfillment', $fulfillmentFilter)) {
                continue;
            }
            $siblings = ShopifyFulfillment::query()->whereIn('shopify_fulfillment_id', self::aliases('Fulfillment', $canonical))->orderBy('id')->get();
            $siblings = $siblings->merge($seeds)->unique('id')->sortBy('id')->values();
            $orderIds = $siblings->map(fn ($f) => self::gid('Order', $f->shopify_order_id))->unique()->values();
            $reservations = ShopifyStackInventoryReservation::query()->with(['movements', 'componentVariant'])
                ->whereIn('shopify_order_id', $orderIds->flatMap(fn ($id) => self::aliases('Order', $id))->all())->orderBy('id')->get();
            $events = ShopifyStackOrderEvent::query()->whereIn('shopify_order_id', $orderIds->flatMap(fn ($id) => self::aliases('Order', $id))->all())
                ->orderByDesc('shopify_updated_at')->orderByDesc('id')->get();
            $names = $reservations->pluck('shopify_order_name')->merge($events->pluck('shopify_order_name'))->filter()->unique()->values();
            if ($orderFilter !== null && ! $orderIds->contains(self::gid('Order', $orderFilter))
                && ! $names->contains('#'.ltrim($orderFilter, '#'))) {
                continue;
            }
            $related = ShopifyFulfillment::query()->whereIn('shopify_order_id', $orderIds->flatMap(fn ($id) => self::aliases('Order', $id))->all())
                ->orderBy('id')->get();
            $groups[] = $this->group($canonical, $siblings, $related, $reservations, $events,
                collect($failed['rows'])->whereIn('target_id', $siblings->pluck('id')->all())->values()->all(),
                collect($queued['rows'])->whereIn('target_id', $siblings->pluck('id')->all())->values()->all(),
                array_filter([$failed['limitation'], $queued['limitation']]));
        }
        $orphans = array_values(array_filter($failed['rows'], fn ($job) => ! $job['target_id'] || ! $byId->has($job['target_id'])));

        return [
            'schema_version' => 1, 'generated_at' => now()->toIso8601String(), 'environment' => app()->environment(),
            'filters' => ['order' => $orderFilter, 'fulfillment' => $fulfillmentFilter],
            'database' => DB::connection()->getDatabaseName(), 'read_only' => true,
            'runtime_files_sha256' => collect(['Services/Shopify/FailedStackFulfillmentDiagnostic.php', 'Models/NewProductDraft.php',
                'Services/Shopify/StackFulfillmentInventoryService.php', 'Services/Shopify/StackOrderReservationService.php',
                'Services/Shopify/StackInventoryMovementService.php'])->mapWithKeys(fn ($file) => [$file => hash_file('sha256', app_path($file))])->all(),
            'shopify_inventory_verified' => false,
            'limitations' => [
                'No Shopify API calls were made. Live reserved balances, adjustment history, cancellations and current fulfillment state are unverified.',
                'Expected components come from current CMS configuration; this is not an immutable historical bill of materials.',
                'Potential safe retry is conditional evidence, not authorization or proof that inventory agrees with Shopify.',
                'Concurrent workers can change production after this snapshot. Recheck immediately before any approved controlled retry.',
                'Separate configured database connections have independent read-only snapshots, not one globally atomic snapshot.',
            ],
            'counts' => ['fulfillment_groups' => count($groups), 'matching_failed_jobs_global' => count($failed['rows']),
                'unlinked_failed_jobs' => count($orphans)] + collect($groups)->countBy('classification')->all(),
            'fulfillments' => $groups,
            'unlinked_failed_jobs' => $orphans,
            'unlinked_queued_jobs' => array_values(array_filter($queued['rows'], fn ($job) => ! $job['target_id'])),
            'unlinked_failed_jobs_scope' => 'global: cannot apply order/fulfillment filters without a linked record',
            'unlinked_failed_job_classification' => self::MANUAL,
        ];
    }

    private function group(string $canonical, Collection $siblings, Collection $allFulfillments, Collection $reservations,
        Collection $events, array $failedJobs, array $queuedJobs, array $limitations): array
    {
        $manual = [];
        $reconcile = [];
        $notes = [];
        $orderIds = $siblings->map(fn ($f) => self::gid('Order', $f->shopify_order_id))->unique()->values()->all();
        if (! preg_match('~^gid://shopify/Fulfillment/\d+$~', $canonical)
            || collect($orderIds)->contains(fn ($id) => ! preg_match('~^gid://shopify/Order/\d+$~', $id))) {
            $manual[] = 'A fulfillment/order identifier is malformed or has the wrong Shopify GID type.';
        }
        if (count($orderIds) !== 1 || $orderIds[0] === '') {
            $manual[] = 'Fulfillment aliases have conflicting or missing order IDs.';
        }
        if ($limitations !== []) {
            $manual = array_merge($manual, $limitations);
        }
        if ($queuedJobs !== [] || $siblings->contains(fn ($f) => $f->processing_status === 'processing')) {
            $manual[] = 'A related job is queued or processing; a controlled retry would race existing work.';
        }
        if ($siblings->contains(fn ($f) => $f->processing_status === 'completed')) {
            $manual[] = 'A numeric/GID alias is already completed. Do not retry blindly; establish whether recovery is already complete.';
        }
        if ($siblings->contains(fn ($f) => strtolower(trim((string) $f->shopify_status)) !== 'success')) {
            $manual[] = 'At least one stored fulfillment status is not success.';
        }
        $signatures = $siblings->map(fn ($f) => $this->payloadSignature($f))->unique();
        if ($signatures->count() !== 1) {
            $manual[] = 'Duplicate fulfillment records have different line/quantity payloads.';
        }
        $fulfillment = $siblings->first();
        $positiveLines = collect((array) data_get($fulfillment->payload, 'line_items', []))
            ->filter(fn ($line) => is_array($line) && (int) ($line['quantity'] ?? 0) > 0)->values();
        if ($positiveLines->isEmpty()) {
            $manual[] = 'No positive-quantity fulfillment lines are available.';
        }
        $lines = [];
        $stackCount = 0;
        $needsOrder = false;
        foreach ($positiveLines as $line) {
            $analysis = $this->line($line, $canonical, $reservations);
            $lines[] = $analysis['report'];
            $manual = array_merge($manual, $analysis['manual']);
            $reconcile = array_merge($reconcile, $analysis['reconcile']);
            $stackCount += $analysis['report']['is_stack'] ? 1 : 0;
            $needsOrder = $needsOrder || $analysis['report']['missing_component_product_ids'] !== [];
        }
        if ($positiveLines->count() !== $positiveLines->map(fn ($line) => self::gid('LineItem', $line['admin_graphql_api_id'] ?? $line['id'] ?? ''))->unique()->count()) {
            $manual[] = 'The fulfillment payload repeats a line ID.';
        }
        if ($stackCount === 0) {
            $manual[] = 'No configured stack line could be independently established; do not infer safety from the historical error.';
        }
        $related = $allFulfillments->filter(fn ($f) => in_array(self::gid('Order', $f->shopify_order_id), $orderIds))->values();
        $movementRows = [];
        foreach ($reservations as $reservation) {
            $movements = $reservation->movements;
            foreach ($movements as $movement) {
                $row = $this->movementRow($movement, $canonical, $reservation);
                $row['matched_canonical_fulfillment_ids'] = $related->map(fn ($f) => self::gid('Fulfillment', $f->shopify_fulfillment_id))->unique()
                    ->filter(fn ($id) => $movement->action === 'consume' && in_array($movement->event_key,
                        $this->consumeKeys($id, self::gid('LineItem', $reservation->shopify_order_line_item_id), $reservation->id), true))->values()->all();
                $movementRows[] = $row;
                if ($movement->action === 'consume' && $row['matched_canonical_fulfillment_ids'] === []) {
                    $reconcile[] = "Consume movement {$movement->id} cannot be attributed to a stored fulfillment identity.";
                }
                if ($movement->status !== 'completed' && ((int) $movement->attempts > 0 || $movement->shopify_response !== null)) {
                    $reconcile[] = "Movement {$movement->id} is unconfirmed after a possible remote call; Shopify may already have adjusted inventory.";
                }
                if ($movement->status === 'completed' && empty($movement->shopify_response)) {
                    $reconcile[] = "Completed movement {$movement->id} has no stored Shopify confirmation.";
                }
                if ($movement->status !== 'completed' && (int) $movement->attempts === 0) {
                    $manual[] = "Unattempted movement intent {$movement->id} exists; verify the persisted event/quantity before any retry.";
                }
            }
            foreach (['reserve' => 'reserved_quantity', 'consume' => 'consumed_quantity', 'release' => 'released_quantity', 'write_off' => 'written_off_quantity', 'restock' => 'restocked_quantity'] as $action => $column) {
                if ((int) $reservation->{$column} !== (int) $movements->where('status', 'completed')->where('action', $action)->sum('quantity')) {
                    $reconcile[] = "Reservation {$reservation->id} {$column} differs from completed {$action} movements (historical baselines/manual changes need verification).";
                }
            }
            if ((int) $reservation->reserved_quantity < (int) $reservation->consumed_quantity + (int) $reservation->released_quantity + (int) $reservation->written_off_quantity) {
                $reconcile[] = "Reservation {$reservation->id} counters exceed its reserved quantity.";
            }
            $payloadQuantities = $related->groupBy(fn ($f) => self::gid('Fulfillment', $f->shopify_fulfillment_id))
                ->map(fn ($aliases) => $aliases->map(fn ($f) => collect((array) data_get($f->payload, 'line_items', []))
                    ->filter(fn ($line) => is_array($line) && self::gid('LineItem', $line['admin_graphql_api_id'] ?? $line['id'] ?? '')
                        === self::gid('LineItem', $reservation->shopify_order_line_item_id))
                    ->sum(fn ($line) => max(0, (int) ($line['quantity'] ?? 0))))->max());
            if ((int) $payloadQuantities->sum() > (int) $reservation->stack_quantity_ordered) {
                $reconcile[] = "Reservation {$reservation->id}: distinct stored fulfillments exceed the ordered stack quantity; check replacements/duplicates/partial-fulfillment history.";
            }
        }
        // Legacy deductions can represent an additional stock deduction outside the reservation ledger.
        $legacy = [];
        if (DB::getSchemaBuilder()->hasTable('shopify_stack_component_deductions')) {
            $legacy = ShopifyStackComponentDeduction::query()->where(fn ($q) => $q->whereIn('shopify_fulfillment_id', $siblings->pluck('id'))
                ->orWhereIn('shopify_order_id', collect($orderIds)->flatMap(fn ($id) => self::aliases('Order', $id))->all()))->get()
                ->map(fn ($row) => $row->only(['id', 'shopify_fulfillment_id', 'shopify_fulfillment_line_item_id', 'configured_component_product_id',
                    'component_variant_id', 'quantity_deducted', 'status', 'attempts', 'idempotency_key', 'shopify_response', 'error_message', 'processed_at']))->all();
            foreach ($legacy as $row) {
                if ($row['status'] === 'completed' || ($row['attempts'] ?? 0) > 0 || ($row['shopify_response'] ?? null) !== null) {
                    $reconcile[] = "Legacy deduction {$row['id']} may already have changed Shopify stock; rule out overlap with reservation consumption.";
                } else {
                    $manual[] = "Legacy deduction intent {$row['id']} exists; verify the old/new workflow boundary.";
                }
            }
        } else {
            $manual[] = 'Legacy deduction history is unavailable.';
        }
        $audits = $this->audits($reservations, $legacy, $manual, $reconcile);
        $poolRisks = $this->poolRisks($reservations);
        if ($poolRisks !== []) {
            $reconcile[] = 'Unconfirmed attempted movements exist against an affected inventory-item/location pool, possibly on another order.';
        }
        $classification = $reconcile !== [] ? self::RECONCILE : ($manual !== [] ? self::MANUAL : self::POTENTIAL);
        if ($classification === self::POTENTIAL) {
            $notes[] = 'All current expected components have compatible reservations; counters match completed movements, inventory identities agree, and no attempted unconfirmed adjustment, legacy deduction or queued retry was found.';
            $notes[] = 'Verify current Shopify reserved balances and adjustment history before selecting this conditional candidate.';
        }
        if ($siblings->count() > 1) {
            $notes[] = 'Numeric/GID aliases form one business fulfillment. Never retry every alias as separate work.';
        }
        $next = 'fulfillment_directly_after_live_inventory_verification';
        if ($needsOrder) {
            $next = 'order_event_required_before_fulfillment';
            $notes[] = 'Fulfillment processing consumes existing snapshots; it does not create missing component reservations.';
            if (! $events->contains(fn ($e) => in_array($e->status, ['pending', 'failed'], true))) {
                $notes[] = 'Existing order events are completed or absent. Replaying a completed event is a no-op; a supported order-event ingestion/reprocessing path must be established without editing database statuses.';
            }
        }
        if ($classification !== self::POTENTIAL) {
            $next = 'do_not_retry_until_findings_are_resolved';
        }

        return [
            'canonical_order_ids' => $orderIds,
            'order_numbers' => $reservations->pluck('shopify_order_name')->merge($events->pluck('shopify_order_name'))->filter()->unique()->values()->all(),
            'canonical_fulfillment_id' => $canonical, 'fulfillment_id_aliases' => self::aliases('Fulfillment', $canonical),
            'stored_fulfillment_records' => $siblings->map(fn ($f) => $this->fulfillmentRow($f))->all(),
            'classification' => $classification, 'reasons' => $classification === self::POTENTIAL
                ? ['CMS evidence passes conditional retry checks; live Shopify inventory has not been verified.']
                : array_values(array_unique(array_merge($reconcile, $manual))),
            'notes' => $notes, 'recommended_sequence' => $next, 'shopify_inventory_verified' => false,
            'order_event_required_to_create_missing_snapshots' => $needsOrder,
            'replayable_order_event_ids' => $events->whereIn('status', ['pending', 'failed'])->pluck('id')->all(),
            'lines' => $lines, 'order_reservations' => $reservations->map(fn ($r) => $r->only(['id', 'shopify_order_id', 'shopify_order_line_item_id',
                'stack_sku', 'component_sku', 'configured_component_product_id', 'component_variant_id', 'component_quantity_per_stack',
                'total_component_quantity_required', 'reserved_quantity', 'consumed_quantity', 'released_quantity', 'written_off_quantity',
                'historical_quantity', 'restocked_quantity', 'status', 'error_message', 'shopify_inventory_item_id', 'shopify_location_id', 'ledger_document_uri',
                'created_at', 'updated_at']))->all(),
            'order_movements' => $movementRows, 'unconfirmed_pool_movements' => $poolRisks,
            'legacy_deductions' => $legacy, 'inventory_audits' => $audits,
            'order_events' => $events->map(fn ($e) => $e->only(['id', 'webhook_id', 'topic', 'status', 'attempts', 'shopify_updated_at', 'error_message']))->all(),
            'related_order_fulfillments' => $related->map(fn ($f) => $this->fulfillmentRow($f))->all(),
            'failed_queue_jobs' => $failedJobs, 'queued_jobs' => $queuedJobs,
        ];
    }

    private function line(array $line, string $fulfillmentId, Collection $orderReservations): array
    {
        $manual = [];
        $reconcile = [];
        $lineId = self::gid('LineItem', $line['admin_graphql_api_id'] ?? $line['id'] ?? '');
        $rows = $orderReservations->filter(fn ($r) => self::gid('LineItem', $r->shopify_order_line_item_id) === $lineId)->values();
        $variant = isset($line['variant_id']) ? Variant::query()->with('product')->whereIn('shopify_id', self::aliases('ProductVariant', $line['variant_id']))->first() : null;
        $draft = null;
        try {
            $draft = NewProductDraft::configuredStackMatching($variant, $line);
        } catch (\RuntimeException $e) {
            $manual[] = $e->getMessage();
        }
        $expected = $draft?->configuredStackComponentQuantities() ?? [];
        $isStack = $draft !== null || $rows->isNotEmpty() || $variant?->product?->is_bundle;
        if ($variant && filled($line['product_id'] ?? null)
            && self::gid('Product', $line['product_id']) !== self::gid('Product', $variant->product?->shopify_id)) {
            $manual[] = "Line {$lineId}: payload product identity conflicts with the synchronized variant product.";
        }
        if (! preg_match('~^gid://shopify/LineItem/\d+$~', $lineId)) {
            $manual[] = 'A positive-quantity fulfillment line has a missing or malformed line ID.';
        }
        if ($isStack && $expected === []) {
            $manual[] = "Line {$lineId}: expected stack configuration is unavailable or empty.";
        }
        if (! $isStack && (! $variant || EngravedBeadConfiguration::fromProduct($variant->product)->participating())) {
            $manual[] = "Line {$lineId}: product identity is unverified or belongs to the engraved-bead workflow; review manually.";
        }
        $missing = array_values(array_diff(array_keys($expected), $rows->pluck('configured_component_product_id')->map(fn ($id) => (int) $id)->all()));
        if ($missing !== []) {
            $manual[] = "Line {$lineId}: missing component snapshots ".implode(', ', $missing).'; fulfillment retry alone cannot create them.';
        }
        $extra = array_values(array_diff($rows->pluck('configured_component_product_id')->map(fn ($id) => (int) $id)->all(), array_keys($expected)));
        if ($extra !== []) {
            $manual[] = "Line {$lineId}: existing components differ from current configuration; verify configuration history.";
        }
        if ($rows->count() !== $rows->pluck('configured_component_product_id')->unique()->count()) {
            $reconcile[] = "Line {$lineId}: duplicate reservation snapshots exist, including possible numeric/GID order aliases.";
        }
        $components = [];
        foreach ($rows as $reservation) {
            $needed = (int) $line['quantity'] * (int) $reservation->component_quantity_per_stack;
            $matches = $reservation->movements->filter(fn ($m) => $m->action === 'consume'
                && in_array($m->event_key, $this->consumeKeys($fulfillmentId, $lineId, $reservation->id), true));
            $completed = $matches->where('status', 'completed');
            if ($completed->count() > 1) {
                $reconcile[] = "Reservation {$reservation->id}: multiple completed consume events match the same canonical fulfillment; possible duplicate Shopify adjustments.";
            }
            if ($matches->count() > 1 && $matches->contains(fn ($m) => $m->status !== 'completed')) {
                $reconcile[] = "Reservation {$reservation->id}: multiple numeric/GID consume intents exist; an alias may hide an unconfirmed adjustment.";
            }
            if ($matches->contains(fn ($m) => (int) $m->quantity !== $needed)) {
                $reconcile[] = "Reservation {$reservation->id}: recorded consume intent differs from fulfillment quantity {$needed}.";
            }
            if ($completed->isEmpty() && $needed > $reservation->remainingReserved()) {
                $reconcile[] = "Reservation {$reservation->id}: needs {$needed} units but only {$reservation->remainingReserved()} remain reserved.";
            }
            if ($reservation->bead_family_id !== null || str_contains((string) $reservation->ledger_document_uri, 'engraved-bead')) {
                $manual[] = "Reservation {$reservation->id}: engraved-bead cutover, family mapping and write controls require separate verification.";
            }
            if (! in_array($reservation->status, ['pending', 'completed'], true)) {
                $manual[] = "Reservation {$reservation->id}: status {$reservation->status} is not an ordinary active/completed reservation.";
            }
            if ((int) $reservation->total_component_quantity_required !== (int) $reservation->stack_quantity_ordered * (int) $reservation->component_quantity_per_stack
                || (int) $reservation->component_quantity_per_stack <= 0) {
                $reconcile[] = "Reservation {$reservation->id}: required quantity or component multiplier is inconsistent.";
            }
            if ((int) $reservation->released_quantity > 0 || (int) $reservation->written_off_quantity > 0 || (int) $reservation->restocked_quantity > 0) {
                $manual[] = "Reservation {$reservation->id}: release/write-off/restock history requires review before fulfillment replay.";
            }
            if ($reservation->shopify_order_id !== self::gid('Order', $reservation->shopify_order_id)
                || $reservation->shopify_order_line_item_id !== $lineId) {
                $manual[] = "Reservation {$reservation->id}: legacy order/line ID representation will not match the current fulfillment lookup.";
            }
            if (! $reservation->componentVariant || ! $reservation->shopify_inventory_item_id || ! $reservation->shopify_location_id
                || ! $reservation->ledger_document_uri || $reservation->componentVariant?->shopify_inventory_item_id !== $reservation->shopify_inventory_item_id) {
                $manual[] = "Reservation {$reservation->id}: snapshotted inventory identity is incomplete or differs from its component variant.";
            }
            if ($reservation->bead_family_id === null && ((int) $reservation->component_product_id !== (int) $reservation->configured_component_product_id
                || (int) $reservation->componentVariant?->product_id !== (int) $reservation->component_product_id)) {
                $manual[] = "Reservation {$reservation->id}: configured component product and physical variant product disagree.";
            }
            if (isset($expected[$reservation->configured_component_product_id])
                && (int) $expected[$reservation->configured_component_product_id] !== (int) $reservation->component_quantity_per_stack) {
                $manual[] = "Reservation {$reservation->id}: component multiplier differs from current configuration.";
            }
            if ((int) $reservation->historical_quantity > 0) {
                $reconcile[] = "Reservation {$reservation->id}: historical quantity requires fulfillment attribution before replay.";
            }
            $components[] = ['reservation_id' => $reservation->id, 'component_sku' => $reservation->component_sku,
                'needed_for_fulfillment' => $needed, 'remaining_reserved' => $reservation->remainingReserved(),
                'matching_consume_movement_ids' => $matches->pluck('id')->all(), 'already_consumed_for_this_fulfillment' => $completed->isNotEmpty()];
        }

        return ['manual' => $manual, 'reconcile' => $reconcile, 'report' => [
            'line_id' => $lineId, 'sku' => $line['sku'] ?? $variant?->sku, 'quantity' => (int) $line['quantity'], 'is_stack' => (bool) $isStack,
            'draft_id' => $draft?->id, 'configuration_updated_at' => $draft?->updated_at?->toIso8601String(),
            'expected_components' => collect($expected)->map(fn ($quantity, $id) => ['product_id' => (int) $id, 'quantity_per_stack' => $quantity,
                'component_skus' => Product::query()->find($id)?->variants()->pluck('sku')->filter()->values()->all() ?? []])->values()->all(),
            'reservation_state' => $rows->isEmpty() ? 'none' : ($missing !== [] ? 'incomplete' : 'present'),
            'missing_component_product_ids' => $missing, 'unexpected_component_product_ids' => $extra, 'components' => $components,
        ]];
    }

    private function consumeKeys(string $fulfillmentId, string $lineId, int $reservationId): array
    {
        return array_map(fn ($id) => hash('sha256', "fulfillment:{$id}:{$lineId}|{$reservationId}|consume"), self::aliases('Fulfillment', $fulfillmentId));
    }

    private function movementRow(ShopifyStackInventoryMovement $movement, string $fulfillmentId, ShopifyStackInventoryReservation $reservation): array
    {
        return $movement->only(['id', 'reservation_id', 'action', 'quantity', 'status', 'attempts', 'event_key', 'idempotency_key',
            'error_message', 'created_at', 'processed_at', 'shopify_response']) + [
                'matches_this_fulfillment' => in_array($movement->event_key, $this->consumeKeys($fulfillmentId, self::gid('LineItem', $reservation->shopify_order_line_item_id), $reservation->id)),
                'remote_outcome' => $movement->status === 'completed' ? 'locally_confirmed' : ((int) $movement->attempts > 0 || $movement->shopify_response !== null ? 'possibly_applied_unconfirmed' : 'no_recorded_remote_attempt'),
                'retry_age_guard_would_block' => $movement->status !== 'completed' && (int) $movement->attempts > 0 && $movement->created_at?->lt(now()->subHours(23)),
            ];
    }

    private function audits(Collection $reservations, array $legacy, array &$manual, array &$reconcile): array
    {
        if (! DB::getSchemaBuilder()->hasTable('inventory_operation_audits')) {
            $manual[] = 'Inventory adjustment audit history is unavailable.';

            return [];
        }
        $movementIds = $reservations->flatMap(fn ($r) => $r->movements->pluck('id'))->all();
        $keys = $reservations->flatMap(fn ($r) => $r->movements->pluck('idempotency_key'))->merge(array_column($legacy, 'idempotency_key'))->filter()->all();
        $rows = InventoryOperationAudit::query()->where(function ($q) use ($movementIds, $legacy, $keys): void {
            $q->where(fn ($q) => $q->where('related_type', ShopifyStackInventoryMovement::class)->whereIn('related_id', $movementIds))
                ->orWhere(fn ($q) => $q->where('related_type', ShopifyStackComponentDeduction::class)->whereIn('related_id', array_column($legacy, 'id')))
                ->orWhereIn('correlation_key', $keys);
        })->orderBy('id')->get();
        foreach ($rows as $row) {
            if ($row->status !== 'succeeded') {
                $reconcile[] = "Inventory audit {$row->id} is not confirmed succeeded; inspect remote adjustment outcome.";
            }
        }
        foreach ($rows->filter(fn ($r) => filled($r->shopify_adjustment_group_id))->groupBy('shopify_adjustment_group_id') as $group) {
            if ($group->map(fn ($r) => $r->related_type.':'.$r->related_id)->unique()->count() > 1) {
                $reconcile[] = 'A Shopify adjustment group is credited to multiple local operations; verify duplicated ledger attribution.';
            }
        }

        return $rows->map(fn ($r) => $r->only(['id', 'correlation_key', 'related_type', 'related_id', 'workflow', 'action', 'status', 'attempts',
            'delta', 'shopify_inventory_item_id', 'shopify_location_id', 'reference_document_uri', 'shopify_adjustment_group_id', 'error_message', 'occurred_at']))->all();
    }

    private function poolRisks(Collection $reservations): array
    {
        $pairs = $reservations->filter(fn ($r) => $r->shopify_inventory_item_id && $r->shopify_location_id)
            ->unique(fn ($r) => $r->shopify_inventory_item_id.'|'.$r->shopify_location_id);
        if ($pairs->isEmpty()) {
            return [];
        }

        return ShopifyStackInventoryMovement::query()->where('status', '!=', 'completed')
            ->where(fn ($q) => $q->where('attempts', '>', 0)->orWhereNotNull('shopify_response'))
            ->whereHas('reservation', function ($q) use ($pairs): void {
                $q->where(function ($q) use ($pairs): void {
                    foreach ($pairs as $r) {
                        $q->orWhere(fn ($q) => $q->where('shopify_inventory_item_id', $r->shopify_inventory_item_id)->where('shopify_location_id', $r->shopify_location_id));
                    }
                });
            })->with('reservation')->get()->map(fn ($m) => $m->only(['id', 'reservation_id', 'action', 'quantity', 'status', 'attempts', 'idempotency_key', 'error_message'])
                + ['order_id' => $m->reservation?->shopify_order_id])->all();
    }

    private function queueEvidence(bool $failed): array
    {
        $driver = config($failed ? 'queue.failed.driver' : 'queue.connections.inventory-ledger.driver');
        if (! in_array($driver, $failed ? ['database', 'database-uuids'] : ['database'], true)) {
            return ['rows' => [], 'limitation' => 'Queue history cannot be verified for configured driver '.($driver ?? 'unset').'.'];
        }
        $connection = DB::connection(config($failed ? 'queue.failed.database' : 'queue.connections.inventory-ledger.connection'));
        $table = config($failed ? 'queue.failed.table' : 'queue.connections.inventory-ledger.table', $failed ? 'failed_jobs' : 'jobs');
        if (! $connection->getSchemaBuilder()->hasTable($table)) {
            return ['rows' => [], 'limitation' => "{$table} history is unavailable on its configured connection."];
        }
        $rows = [];
        foreach ($connection->table($table)->orderBy('id')->cursor() as $job) {
            if ($failed && ! self::missingError($job->exception)) {
                continue;
            }
            $payload = json_decode($job->payload, true) ?: [];
            if (($payload['displayName'] ?? '') !== ProcessShopifyStackFulfillmentJob::class) {
                continue;
            }
            preg_match('/"fulfillmentId";i:(\d+);/', (string) data_get($payload, 'data.command'), $match);
            $rows[] = ['id' => $job->id, 'uuid' => $job->uuid ?? $payload['uuid'] ?? null, 'queue' => $job->queue,
                'target_id' => isset($match[1]) ? (int) $match[1] : null,
                'failed_at' => $job->failed_at ?? null, 'attempts' => $job->attempts ?? null,
                'reserved_at' => $job->reserved_at ?? null, 'available_at' => $job->available_at ?? null,
                'error' => $failed ? mb_substr(strtok($job->exception, "\n"), 0, 2000) : null];
        }

        return ['rows' => $rows, 'limitation' => ! $failed && collect($rows)->contains(fn ($row) => ! $row['target_id'])
            ? 'A queued fulfillment job cannot be linked to a CMS record; concurrent processing cannot be ruled out.' : null];
    }

    private function payloadSignature(ShopifyFulfillment $fulfillment): string
    {
        return collect((array) data_get($fulfillment->payload, 'line_items', []))->filter(fn ($line) => is_array($line))
            ->map(fn ($line) => implode('|', [self::gid('LineItem', $line['admin_graphql_api_id'] ?? $line['id'] ?? ''),
                (int) ($line['quantity'] ?? 0), self::gid('ProductVariant', $line['variant_id'] ?? ''),
                self::gid('Product', $line['product_id'] ?? ''), $line['sku'] ?? '']))->sort()->values()->toJson();
    }

    private function fulfillmentRow(ShopifyFulfillment $f): array
    {
        return $f->only(['id', 'shopify_order_id', 'shopify_fulfillment_id', 'shopify_status', 'processing_status', 'attempts',
            'error_message', 'fulfilled_at_shopify', 'processing_started_at', 'processed_at', 'created_at', 'updated_at']);
    }
}
