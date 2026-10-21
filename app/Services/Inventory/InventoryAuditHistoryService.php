<?php

namespace App\Services\Inventory;

use App\Filament\Resources\ManualStockTransactionResource;
use App\Filament\Resources\ProcurementSupplierOrderResource;
use App\Filament\Resources\ProductResource;
use App\Filament\Resources\UserResource;
use App\Models\ChangeLog;
use App\Models\InventoryAdjustmentRequest;
use App\Models\InventoryAdjustmentRequestItem;
use App\Models\InventoryAuditReview;
use App\Models\InventoryOperationAudit;
use App\Models\ShopifyInventoryAdjustment;
use App\Models\ShopifyInventoryHistorySync;
use Illuminate\Database\QueryException;
use App\Models\ManualStockTransactionImpact;
use App\Models\ProcurementSupplierReceipt;
use App\Models\ShopifyStackInventoryMovement;
use App\Models\User;
use App\Models\Variant;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;

final class InventoryAuditHistoryService
{
    private const SOURCE_CAP = 2000;

    /**
     * Inventory quantities a person can change on a variant. Product content edits stay on the product change log.
     *
     * @var array<string, array{0:string,1:string,2:string}>
     */
    private const PERSON_INVENTORY_FIELDS = [
        'current_on_hand_quantity' => ['on_hand', 'On hand edited', 'on hand'],
        'current_available_quantity' => ['available', 'Available quantity edited', 'available'],
        'current_inventory_quantity' => ['available', 'Available quantity edited', 'available'],
        'inventory_qty' => ['available', 'Available quantity edited', 'available'],
        'current_committed_quantity' => ['committed', 'Committed quantity edited', 'committed'],
        'current_reserved_quantity' => ['reserved', 'Reserved quantity edited', 'reserved'],
        'current_incoming_quantity' => ['incoming', 'Incoming quantity edited', 'incoming'],
        'current_damaged_quantity' => ['damaged', 'Damaged quantity edited', 'damaged'],
        'current_quality_control_quantity' => ['quality_control', 'Quality control quantity edited', 'quality control'],
        'current_safety_stock_quantity' => ['safety_stock', 'Safety stock quantity edited', 'safety stock'],
    ];

    private bool $hitCap = false;

    public function __construct(
        private readonly ShopifyInventoryHistoryQuery $shopifyHistory,
        private readonly InventoryAuditMatcher $matcher,
        private readonly InventoryAuditOperationGrouper $grouper,
        private readonly InventoryAuditExplanation $explanation,
    ) {}

    /**
     * @param array<string, mixed> $filters
     * @return array{rows:array<int, array<string, mixed>>,shopify_status:string,shopify_message:?string,truncated:bool,summary:array<string, int>,retrieved_at:?string,incomplete:bool}
     */
    public function search(array $filters): array
    {
        $this->hitCap = false;
        $from = Carbon::parse((string) ($filters['from'] ?? now()->subDays(7)->toDateString()))->startOfDay();
        $until = Carbon::parse((string) ($filters['until'] ?? now()->toDateString()))->endOfDay();
        $query = trim((string) ($filters['query'] ?? ''));
        $activity = trim((string) ($filters['activity'] ?? ''));
        $source = trim((string) ($filters['source'] ?? ''));
        $actor = trim((string) ($filters['triggered_by'] ?? ''));
        $location = trim((string) ($filters['location'] ?? ''));
        $reference = trim((string) ($filters['reference'] ?? ''));
        $app = trim((string) ($filters['app'] ?? ''));
        $reason = trim((string) ($filters['reason'] ?? ''));
        $state = trim((string) ($filters['state'] ?? ''));
        $direction = trim((string) ($filters['direction'] ?? ''));
        $match = trim((string) ($filters['match'] ?? ''));
        $investigation = trim((string) ($filters['investigation'] ?? ''));
        $storewide = (bool) ($filters['storewide'] ?? false);
        $liveShopify = (bool) ($filters['live_shopify'] ?? true);
        if (in_array($investigation, ['cms', 'unexplained'], true) && $app === '') {
            $app = 'la-cms-access';
        }

        $shopify = $this->shopifyRows($query, $from, $until, $storewide, $app, $liveShopify);
        $cited = $this->citedReferences($shopify['rows']);
        $local = array_merge(
            $this->receipts($from, $until, $query, $reference, $cited['receipts']),
            $this->manualAdjustments($from, $until, $query, $reference, $cited['manual']),
            $this->stackMovements($from, $until, $query, $reference, $cited['movements']),
            $this->corrections($from, $until, $query, $reference),
        );
        $local = $this->uniqueLocal($local);
        $local = $this->attachOperationAudits($local, $this->operationAudits($from, $until, $query, $reference));
        $rows = $shopify['rows'] === []
            ? array_map(fn (array $row): array => $row['row'], $local)
            : $this->matcher->merge($local, $shopify['rows']);
        $rows = $this->grouper->group($rows);
        $rows = array_map(fn (array $row): array => $this->explanation->apply($row), $rows);
        foreach ($this->personChangeLogs($from, $until, $query, $reference) as $change) {
            $rows[] = $this->explanation->apply($change['row']);
        }

        $rows = array_values(array_filter($rows, function (array $row) use ($activity, $source, $actor, $location, $reason, $state, $direction, $match, $investigation, $app): bool {
            if ($activity !== '' && ($row['activity_key'] ?? '') !== $activity) {
                return false;
            }
            if ($source === 'cms' && ! $this->isCmsRow($row)) {
                return false;
            }
            if ($source !== '' && $source !== 'cms' && ($row['source'] ?? '') !== $source) {
                return false;
            }
            if ($actor !== '' && ! str_contains(strtolower((string) ($row['triggered_by'] ?? '')), strtolower($actor))) {
                return false;
            }
            if ($location !== '' && ! str_contains(strtolower((string) ($row['location'] ?? '')), strtolower($location))) {
                return false;
            }
            if ($app !== '' && strtolower((string) ($row['app'] ?? '')) !== strtolower($app) && ! ($app === 'la-cms-access' && in_array($row['source'] ?? '', ['cms', 'automation'], true) && ($row['reported_by'] ?? '') === 'local')) {
                return false;
            }
            if ($reason !== '' && ! str_contains(strtolower((string) json_encode($row['detail'] ?? [])), strtolower($reason))) {
                return false;
            }
            if ($state !== '' && ! $this->hasState($row, $state)) {
                return false;
            }
            if ($direction === 'positive' && ! $this->hasDirection($row, true)) {
                return false;
            }
            if ($direction === 'negative' && ! $this->hasDirection($row, false)) {
                return false;
            }
            if ($match !== '' && ($row['confidence'] ?? '') !== $match) {
                return false;
            }
            if ($investigation === 'cms' && ! $this->isCmsRow($row)) {
                return false;
            }
            if ($investigation === 'unexplained' && ($row['flags'] ?? []) === []) {
                return false;
            }

            return true;
        }));
        $rows = $this->attachReviews($rows);

        $localNote = $this->hitCap
            ? ' Local CMS records hit the '.self::SOURCE_CAP.'-row page for at least one source. Narrow the date range. Records past that page are missing from this result.'
            : '';

        return [
            'rows' => $rows,
            'shopify_status' => $shopify['status'],
            'shopify_message' => trim(((string) $shopify['message']).$localNote),
            'truncated' => $shopify['incomplete'] || $this->hitCap,
            'incomplete' => $shopify['incomplete'] || $this->hitCap,
            'retrieved_at' => $shopify['retrieved_at'],
            'summary' => $this->summary($rows),
        ];
    }

    /**
     * @return array{status:string,message:?string,rows:array<int, array<string, mixed>>,incomplete:bool,retrieved_at:?string}
     */
    private function shopifyRows(string $query, Carbon $from, Carbon $until, bool $storewide, string $app, bool $liveShopify = true): array
    {
        if (! $liveShopify) {
            return $this->storedShopifyRows($query, $from, $until, $app);
        }

        $variant = $this->singleVariant($query);
        $sku = $variant instanceof Variant ? trim((string) $variant->sku) : '';
        if (! $storewide && $sku === '') {
            return [
                'status' => 'skipped',
                'message' => $query === ''
                    ? 'Enter one product SKU to include Shopify adjustment history. This search is showing CMS records only.'
                    : 'Shopify history is loaded for one exact SKU. This search matched zero or several variants, so only CMS records are shown.',
                'rows' => [],
                'incomplete' => false,
                'retrieved_at' => null,
            ];
        }

        $result = $this->shopifyHistory->collect($from, $until, $sku !== '' ? $sku : null, $app !== '' ? $app : null);
        $needle = strtolower($query);
        $rows = [];
        foreach ($result['rows'] as $remote) {
            if ($this->isShopifySale($remote)) {
                continue;
            }
            if ($needle !== '' && $sku === '' && ! $this->remoteMatchesQuery($remote, $needle)) {
                continue;
            }
            $rows[] = $this->shopifyCandidate($remote);
        }
        $rows = $this->enrichProducts($rows);

        return [
            'status' => $result['status'],
            'message' => $result['message'],
            'rows' => $rows,
            'incomplete' => (bool) ($result['incomplete'] ?? false),
            'retrieved_at' => $result['retrieved_at'] ?? null,
        ];
    }

    /**
     * @return array{status:string,message:?string,rows:array<int, array<string, mixed>>,incomplete:bool,retrieved_at:?string}
     */
    private function storedShopifyRows(string $query, Carbon $from, Carbon $until, string $app): array
    {
        try {
            $records = ShopifyInventoryAdjustment::query()
                ->whereBetween('occurred_at', [$from, $until])
                ->when($app !== '', fn ($builder) => $builder->where('app_name', $app))
                ->when($query !== '', function ($builder) use ($query): void {
                    $builder->where(function ($inner) use ($query): void {
                        $inner->where('sku', 'like', '%'.$query.'%')
                            ->orWhere('product_title', 'like', '%'.$query.'%');
                    });
                })
                ->orderByDesc('occurred_at')
                ->limit(5000)
                ->get();
            $latest = ShopifyInventoryHistorySync::query()->max('finished_at');
        } catch (QueryException) {
            return [
                'status' => 'skipped',
                'message' => 'Stored Shopify history is not available yet. CMS records are shown.',
                'rows' => [],
                'incomplete' => false,
                'retrieved_at' => null,
            ];
        }

        $rows = [];
        foreach ($records as $record) {
            $remote = [
                'second' => optional($record->occurred_at)->toIso8601String(),
                'inventory_adjustment_id' => $record->adjustment_id,
                'inventory_adjustment_group_id' => $record->adjustment_group_id,
                'inventory_adjustment_change' => (string) $record->delta,
                'inventory_item_id' => $record->inventory_item_id,
                'product_variant_id' => $record->product_variant_id,
                'product_variant_sku' => $record->sku,
                'product_variant_title' => $record->product_title,
                'inventory_location_id' => $record->location_id,
                'inventory_location_name' => $record->location_name,
                'inventory_state' => $record->inventory_state,
                'inventory_change_reason' => $record->reason,
                'inventory_app_name' => $record->app_name,
                'staff_member_name' => $record->staff_name,
                'reference_document_uri' => $record->reference_document_uri,
                'reference_document_type' => $record->reference_document_type,
                'reference_document_id' => $record->reference_document_id,
            ];
            if ($this->isShopifySale($remote)) {
                continue;
            }
            $rows[] = $this->shopifyCandidate($remote);
        }

        $incomplete = $records->count() >= 5000;
        $when = $latest ? Carbon::parse($latest)->timezone(config('app.timezone'))->format('M j, Y H:i') : null;
        $message = $when
            ? 'Showing Shopify history stored in this CMS. Last sync finished '.$when.'.'
            : 'Shopify history has not been stored yet. CMS records are shown. Load Shopify adjustments with inventory:sync-shopify-history.';
        if ($incomplete) {
            $message .= ' This date range has more stored Shopify rows than this page can list. Narrow the dates.';
        }

        return [
            'status' => 'ok',
            'message' => $message,
            'rows' => $this->enrichProducts($rows),
            'incomplete' => $incomplete,
            'retrieved_at' => $latest ? Carbon::parse($latest)->toIso8601String() : null,
        ];
    }

    /**
     * @param array<int, string> $citedUris
     * @return array<int, array<string, mixed>>
     */
    private function receipts(Carbon $from, Carbon $until, string $query, string $reference, array $citedUris = []): array
    {
        $records = ProcurementSupplierReceipt::query()
            ->with(['line.order', 'line.variant.product', 'createdBy'])
            ->where('status', '!=', 'pending')
            ->where(function (Builder $builder) use ($from, $until, $citedUris): void {
                $builder->whereBetween('created_at', [$from, $until])
                    ->orWhereBetween('shopify_adjusted_at', [$from, $until]);
                if ($citedUris !== []) {
                    $builder->orWhereIn('shopify_reference_uri', $citedUris);
                }
            })
            ->when($query !== '', function (Builder $builder) use ($query): void {
                $builder->where(function (Builder $inner) use ($query): void {
                    $inner->whereHas('line', function (Builder $line) use ($query): void {
                        $line->where('sku', 'like', '%'.$query.'%')
                            ->orWhereHas('variant.product', fn (Builder $product): Builder => $product->where('title', 'like', '%'.$query.'%'));
                    });
                });
            })
            ->when($reference !== '', function (Builder $builder) use ($reference): void {
                $builder->where(function (Builder $inner) use ($reference): void {
                    $inner->where('grv_number', 'like', '%'.$reference.'%')
                        ->orWhere('uuid', 'like', '%'.$reference.'%')
                        ->orWhere('shopify_reference_uri', 'like', '%'.$reference.'%')
                        ->orWhereHas('line.order', fn (Builder $order): Builder => $order->where('order_number', 'like', '%'.$reference.'%'));
                });
            })
            ->orderByDesc('id')
            ->limit(self::SOURCE_CAP + 1)
            ->get();
        $records = $this->cap($records);
        if ($citedUris !== []) {
            $loaded = $records->pluck('shopify_reference_uri')->filter()->all();
            $missing = array_values(array_diff($citedUris, $loaded));
            if ($missing !== []) {
                $records = $records->concat(
                    ProcurementSupplierReceipt::query()
                        ->with(['line.order', 'line.variant.product', 'createdBy'])
                        ->where('status', '!=', 'pending')
                        ->whereIn('shopify_reference_uri', $missing)
                        ->get()
                );
            }
        }

        return $records->map(function (ProcurementSupplierReceipt $receipt): array {
            $line = $receipt->line;
            $variant = $line?->variant;
            $occurredAt = $receipt->shopify_adjusted_at ?? $receipt->created_at ?? now();
            $orderNumber = $line?->order?->order_number;

            return $this->candidate(
                key: 'receipt:'.$receipt->id,
                occurredAt: Carbon::parse($occurredAt),
                activityKey: 'received',
                activity: 'Supplier inventory received',
                change: '+'.(int) $receipt->quantity_received.' available',
                available: null,
                onHand: null,
                source: 'cms',
                triggeredBy: $this->actor($receipt->createdBy, 'Supplier receipt job'),
                status: (string) $receipt->status,
                location: null,
                references: array_filter([(string) $receipt->shopify_reference_uri]),
                inventoryItemId: $variant?->shopify_inventory_item_id,
                locationId: null,
                delta: (int) $receipt->quantity_received,
                state: 'available',
                detail: array_filter([
                    ['SKU', $line?->sku],
                    ['Product', $variant?->product?->title],
                    ['Purchase order', $orderNumber],
                    ['Receipt', $receipt->grv_number ?: $receipt->uuid],
                    ['Reason', 'Supplier stock received'],
                    ['Shopify reference', $receipt->shopify_reference_uri],
                    ['Status', $this->statusLabel((string) $receipt->status)],
                    ['Evidence', 'The receipt stores the reference sent to Shopify. Available and on-hand balances after the receipt were not read back from Shopify.'],
                    $receipt->error ? ['Error', $receipt->error] : null,
                ]),
                links: array_filter([
                    $this->productLink($variant?->product_id),
                    $line?->supplier_order_id && ($url = $this->safeUrl(fn (): string => ProcurementSupplierOrderResource::getUrl('view', ['record' => $line->supplier_order_id])))
                        ? ['Purchase order', $url]
                        : null,
                    $this->shopifyProductLink($variant?->product?->shopify_id),
                ]),
                represented: ['reference:'.strtolower((string) $receipt->shopify_reference_uri)],
                actorUserId: $receipt->created_by,
                process: 'Supplier receipt job',
            );
        })->all();
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    /**
     * @param array<int, int> $citedIds
     * @return array<int, array<string, mixed>>
     */
    private function manualAdjustments(Carbon $from, Carbon $until, string $query, string $reference, array $citedIds = []): array
    {
        $records = ManualStockTransactionImpact::query()
            ->with(['transaction.processor', 'transaction.creator', 'variant.product'])
            ->where('status', '!=', 'pending')
            ->where(function (Builder $builder) use ($from, $until, $citedIds): void {
                $builder->whereBetween('created_at', [$from, $until])
                    ->orWhereBetween('processed_at', [$from, $until]);
                if ($citedIds !== []) {
                    $builder->orWhereIn('id', $citedIds);
                }
            })
            ->when($query !== '', function (Builder $builder) use ($query): void {
                $builder->where(function (Builder $inner) use ($query): void {
                    $inner->where('sku', 'like', '%'.$query.'%')
                        ->orWhere('product_title', 'like', '%'.$query.'%');
                });
            })
            ->when($reference !== '', function (Builder $builder) use ($reference): void {
                $builder->whereHas('transaction', fn (Builder $transaction): Builder => $transaction->where('reference_number', 'like', '%'.$reference.'%'));
            })
            ->orderByDesc('id')
            ->limit(self::SOURCE_CAP + 1)
            ->get();
        $records = $this->cap($records);
        if ($citedIds !== []) {
            $missing = array_values(array_diff($citedIds, $records->pluck('id')->all()));
            if ($missing !== []) {
                $records = $records->concat(
                    ManualStockTransactionImpact::query()
                        ->with(['transaction.processor', 'transaction.creator', 'variant.product'])
                        ->where('status', '!=', 'pending')
                        ->whereIn('id', $missing)
                        ->get()
                );
            }
        }

        return $records->map(function (ManualStockTransactionImpact $impact): array {
            $transaction = $impact->transaction;
            $referenceUri = $transaction ? "gid://la-cms/ManualStockTransaction/{$transaction->id}/Impact/{$impact->id}" : null;
            $occurredAt = $impact->processed_at ?? $impact->created_at ?? now();
            $user = $transaction?->processor ?: $transaction?->creator;

            return $this->candidate(
                key: 'manual:'.$impact->id,
                occurredAt: Carbon::parse($occurredAt),
                activityKey: 'manual_deduction',
                activity: 'Manual stock deduction',
                change: '-'.(int) $impact->quantity_required.' on hand',
                available: $impact->available_after !== null ? (string) $impact->available_after : null,
                onHand: $impact->on_hand_after !== null ? (string) $impact->on_hand_after : null,
                source: 'cms',
                triggeredBy: $this->actor($user, 'Manual stock job'),
                status: (string) $impact->status,
                location: $impact->shopify_location_id,
                references: array_filter([$referenceUri]),
                inventoryItemId: $impact->variant?->shopify_inventory_item_id,
                locationId: $impact->shopify_location_id,
                delta: -1 * (int) $impact->quantity_required,
                state: 'on_hand',
                detail: array_filter([
                    ['SKU', $impact->sku],
                    ['Product', $impact->product_title],
                    ['Reason', $transaction?->notes ?: ($transaction?->transaction_type)],
                    ['Reference', $transaction?->reference_number],
                    ['Available before', $impact->available_before !== null ? (string) $impact->available_before : null],
                    ['Available after', $impact->available_after !== null ? (string) $impact->available_after.' (read back from Shopify)' : null],
                    ['On hand before', $impact->on_hand_before !== null ? (string) $impact->on_hand_before : null],
                    ['On hand after', $impact->on_hand_after !== null ? (string) $impact->on_hand_after.' (read back from Shopify)' : null],
                    ['Shopify reference', $referenceUri],
                    ['Status', $this->statusLabel((string) $impact->status)],
                    $impact->error_message ? ['Error', $impact->error_message] : null,
                ]),
                links: array_filter([
                    $this->productLink($impact->variant?->product_id),
                    $transaction && ($manualUrl = $this->safeUrl(fn (): string => ManualStockTransactionResource::getUrl('view', ['record' => $transaction])))
                        ? ['Manual stock transaction', $manualUrl]
                        : null,
                    $this->shopifyProductLink($impact->variant?->product?->shopify_id),
                ]),
                represented: array_filter([
                    $referenceUri ? 'reference:'.strtolower($referenceUri) : null,
                    $impact->idempotency_key ? 'correlation:'.strtolower((string) $impact->idempotency_key) : null,
                ]),
                actorUserId: $user?->id,
                process: 'Manual stock job',
            );
        })->all();
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    /**
     * @param array<int, int> $citedIds
     * @return array<int, array<string, mixed>>
     */
    private function stackMovements(Carbon $from, Carbon $until, string $query, string $reference, array $citedIds = []): array
    {
        $records = ShopifyStackInventoryMovement::query()
            ->with('reservation')
            ->where('status', '!=', ShopifyStackInventoryMovement::STATUS_PENDING)
            ->where(function (Builder $builder) use ($from, $until, $citedIds): void {
                $builder->whereBetween('created_at', [$from, $until])
                    ->orWhereBetween('processed_at', [$from, $until]);
                if ($citedIds !== []) {
                    $builder->orWhereIn('id', $citedIds);
                }
            })
            ->when($query !== '', function (Builder $builder) use ($query): void {
                $builder->whereHas('reservation', function (Builder $reservation) use ($query): void {
                    $reservation->where(function (Builder $inner) use ($query): void {
                        $inner->where('component_sku', 'like', '%'.$query.'%')
                            ->orWhere('component_title', 'like', '%'.$query.'%')
                            ->orWhere('stack_sku', 'like', '%'.$query.'%')
                            ->orWhere('stack_title', 'like', '%'.$query.'%');
                    });
                });
            })
            ->when($reference !== '', function (Builder $builder) use ($reference): void {
                $builder->whereHas('reservation', function (Builder $reservation) use ($reference): void {
                    $reservation->where('shopify_order_name', 'like', '%'.$reference.'%')
                        ->orWhere('stack_sku', 'like', '%'.$reference.'%')
                        ->orWhere('shopify_order_id', 'like', '%'.$reference.'%');
                });
            })
            ->orderByDesc('id')
            ->limit(self::SOURCE_CAP + 1)
            ->get();
        $records = $this->cap($records);
        if ($citedIds !== []) {
            $missing = array_values(array_diff($citedIds, $records->pluck('id')->all()));
            if ($missing !== []) {
                $records = $records->concat(
                    ShopifyStackInventoryMovement::query()
                        ->with('reservation')
                        ->where('status', '!=', ShopifyStackInventoryMovement::STATUS_PENDING)
                        ->whereIn('id', $missing)
                        ->get()
                );
            }
        }

        return $records->map(function (ShopifyStackInventoryMovement $movement): array {
            $reservation = $movement->reservation;
            $referenceUri = 'gid://leigh-avenue-cms/StackInventoryMovement/'.$movement->id;
            [$activityKey, $activity, $change, $state, $delta] = match ($movement->action) {
                ShopifyStackInventoryMovement::ACTION_RELEASE => ['reservation_deleted', 'Stack reservation released', '-'.(int) $movement->quantity.' reserved', 'reserved', -1 * (int) $movement->quantity],
                ShopifyStackInventoryMovement::ACTION_CONSUME => ['consumed', 'Reserved stack inventory consumed', '-'.(int) $movement->quantity.' reserved', 'reserved', -1 * (int) $movement->quantity],
                default => ['reserved', 'Stack inventory reserved', '+'.(int) $movement->quantity.' reserved', 'reserved', (int) $movement->quantity],
            };
            $occurredAt = $movement->processed_at ?? $movement->created_at ?? now();

            return $this->candidate(
                key: 'stack:'.$movement->id,
                occurredAt: Carbon::parse($occurredAt),
                activityKey: $activityKey,
                activity: $activity,
                change: $change,
                available: null,
                onHand: null,
                source: 'automation',
                triggeredBy: 'Order processing',
                status: (string) $movement->status,
                location: $reservation?->shopify_location_id,
                references: [$referenceUri],
                inventoryItemId: $reservation?->shopify_inventory_item_id,
                locationId: $reservation?->shopify_location_id,
                delta: $delta,
                state: $state,
                detail: array_filter([
                    ['Component SKU', $reservation?->component_sku],
                    ['Component', $reservation?->component_title],
                    ['Stack SKU', $reservation?->stack_sku],
                    ['Stack', $reservation?->stack_title],
                    ['Shopify order', $reservation?->shopify_order_name],
                    ['Quantity', (string) $movement->quantity],
                    ['Reason', match ($movement->action) {
                        ShopifyStackInventoryMovement::ACTION_RELEASE => 'The stack reservation was released.',
                        ShopifyStackInventoryMovement::ACTION_CONSUME => 'Reserved component inventory was consumed when the stack was fulfilled.',
                        default => 'Component inventory was reserved because an order included this stack.',
                    }],
                    ['Triggered by', 'Order processing. No CMS user is stored for this workflow.'],
                    ['Shopify reference', $referenceUri],
                    ['Status', $this->statusLabel((string) $movement->status)],
                    $movement->error_message ? ['Error', $movement->error_message] : null,
                ]),
                links: array_filter([
                    $this->productLink($reservation?->component_product_id),
                    $this->shopifyProductLink($reservation?->shopify_component_product_id),
                ]),
                represented: [
                    'reference:'.strtolower($referenceUri),
                    'correlation:'.strtolower((string) $movement->idempotency_key),
                ],
                process: 'Order processing',
            );
        })->all();
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function corrections(Carbon $from, Carbon $until, string $query, string $reference): array
    {
        $records = InventoryAdjustmentRequestItem::query()
            ->with(['request.requester', 'request.approver', 'variant.product'])
            ->whereHas('request', function (Builder $request) use ($from, $until, $reference): void {
                $request->whereIn('status', [InventoryAdjustmentRequest::STATUS_APPLIED, InventoryAdjustmentRequest::STATUS_FAILED])
                    ->where(function (Builder $dates) use ($from, $until): void {
                        $dates->whereBetween('updated_at', [$from, $until])
                            ->orWhereBetween('applied_at', [$from, $until]);
                    })
                    ->when($reference !== '', function (Builder $inner) use ($reference): void {
                        $inner->where(function (Builder $match) use ($reference): void {
                            $match->where('uuid', 'like', '%'.$reference.'%')
                                ->orWhere('sync_batch_id', 'like', '%'.$reference.'%');
                        });
                    });
            })
            ->when($query !== '', function (Builder $builder) use ($query): void {
                $builder->where(function (Builder $inner) use ($query): void {
                    $inner->where('sku', 'like', '%'.$query.'%')
                        ->orWhereHas('variant.product', fn (Builder $product): Builder => $product->where('title', 'like', '%'.$query.'%'));
                });
            })
            ->orderByDesc('id')
            ->limit(self::SOURCE_CAP + 1)
            ->get();
        $records = $this->cap($records);

        return $records->map(function (InventoryAdjustmentRequestItem $item): array {
            $request = $item->request;
            $occurredAt = $request?->applied_at ?? $request?->updated_at ?? $item->updated_at ?? now();
            $referenceUri = 'logistics://shopify-editor/inventory/'.rawurlencode((string) ($item->variant?->shopify_id ?? ''));
            $before = $item->original_on_hand_quantity;
            $after = $item->requested_on_hand_quantity;
            $delta = $before !== null && $after !== null ? (int) $after - (int) $before : null;

            return $this->candidate(
                key: 'correction:'.$item->id,
                occurredAt: Carbon::parse($occurredAt),
                activityKey: 'correction',
                activity: 'Inventory correction',
                change: $after !== null ? 'Set on hand to '.$after : 'On-hand correction',
                available: null,
                onHand: null,
                source: 'cms',
                triggeredBy: $this->actor($request?->requester, 'Inventory sync job'),
                status: (string) ($request?->status ?? ''),
                location: null,
                references: [],
                inventoryItemId: $item->variant?->shopify_inventory_item_id,
                locationId: null,
                delta: $delta ?? 0,
                state: 'on_hand',
                detail: array_filter([
                    ['SKU', $item->sku ?: $item->variant?->sku],
                    ['Product', $item->variant?->product?->title],
                    ['Reason', $item->reason],
                    ['Requested by', $request?->requester?->name],
                    ['Approved by', $request?->approver?->name],
                    ['On hand before, as recorded in the CMS', $before !== null ? (string) $before : null],
                    ['On hand sent to Shopify', $after !== null ? (string) $after : null],
                    ['Shopify reference', $referenceUri],
                    ['Reference limitation', 'This correction reuses one reference per Shopify variant, so the reference alone cannot confirm which history row it was.'],
                    ['Status', $this->statusLabel((string) ($request?->status ?? ''))],
                ]),
                links: array_filter([
                    $this->productLink($item->variant?->product_id),
                    $this->shopifyProductLink($item->variant?->product?->shopify_id),
                ]),
                represented: array_filter([
                    $request?->sync_batch_id ? 'correlation:inventory-set:'.strtolower($request->sync_batch_id.':'.$item->variant_id) : null,
                ]),
                actorUserId: $request?->requester?->id,
                process: 'Inventory sync job',
            );
        })->all();
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function operationAudits(Carbon $from, Carbon $until, string $query, string $reference): array
    {
        $records = InventoryOperationAudit::query()
            ->with(['actor', 'variant.product'])
            ->whereBetween('occurred_at', [$from, $until])
            ->when($query !== '', function (Builder $builder) use ($query): void {
                $builder->where(function (Builder $inner) use ($query): void {
                    $inner->where('sku', 'like', '%'.$query.'%')
                        ->orWhereHas('product', fn (Builder $product): Builder => $product->where('title', 'like', '%'.$query.'%'));
                });
            })
            ->when($reference !== '', function (Builder $builder) use ($reference): void {
                $builder->where(function (Builder $inner) use ($reference): void {
                    $inner->where('reference_document_uri', 'like', '%'.$reference.'%')
                        ->orWhere('correlation_key', 'like', '%'.$reference.'%');
                });
            })
            ->orderByDesc('id')
            ->limit(self::SOURCE_CAP + 1)
            ->get();
        $records = $this->cap($records);

        return $records->map(function (InventoryOperationAudit $audit): array {
            $reference = trim((string) $audit->reference_document_uri);
            $referenceIsUnique = $reference !== '' && ! str_starts_with($reference, 'logistics://shopify-editor/inventory/');
            $keys = array_values(array_filter([
                $referenceIsUnique ? 'reference:'.strtolower($reference) : null,
                'correlation:'.strtolower($audit->correlation_key),
            ]));
            $activity = match ($audit->workflow) {
                'product_initialization' => ['initialized', 'New product inventory initialized'],
                'inventory_correction' => ['correction', 'Inventory correction'],
                'supplier_receipt' => ['received', 'Supplier inventory received'],
                'manual_stock' => ['manual_deduction', 'Manual stock deduction'],
                'stack_reserve' => ['reserved', 'Stack inventory reserved'],
                'stack_release' => ['reservation_deleted', 'Stack reservation released'],
                'stack_consume' => ['consumed', 'Reserved stack inventory consumed'],
                default => ['shopify_other', 'Inventory operation'],
            };

            return $this->candidate(
                key: 'audit:'.$audit->id,
                occurredAt: Carbon::parse($audit->occurred_at ?? $audit->created_at),
                activityKey: $activity[0],
                activity: $activity[1],
                change: $audit->delta !== null
                    ? (($audit->delta > 0 ? '+' : '').$audit->delta.' '.str_replace('_', ' ', (string) $audit->inventory_state))
                    : ($audit->quantity_after !== null ? 'Set '.$audit->inventory_state.' to '.$audit->quantity_after : 'Quantity not stored'),
                available: null,
                onHand: null,
                source: $audit->actor_user_id ? 'cms' : 'automation',
                triggeredBy: $this->actor($audit->actor, (string) ($audit->process_name ?: 'Background job'), $audit->execution === 'queue'),
                status: (string) $audit->status,
                location: $audit->shopify_location_id,
                references: $referenceIsUnique ? [$reference] : [],
                inventoryItemId: $audit->shopify_inventory_item_id,
                locationId: $audit->shopify_location_id,
                delta: (int) ($audit->delta ?? 0),
                state: (string) $audit->inventory_state,
                detail: array_filter([
                    ['SKU', $audit->sku],
                    ['Product', $audit->variant?->product?->title],
                    ['Reason', $audit->explanation],
                    ['Shopify reason', $audit->shopify_reason],
                    ['Attempts', (string) $audit->attempts],
                    ['Shopify reference', $audit->reference_document_uri],
                    ['Shopify adjustment group', $audit->shopify_adjustment_group_id],
                    ['Status', $this->statusLabel((string) $audit->status)],
                    $audit->attempts > 1 ? ['Retries', 'The same operation was attempted more than once. Those attempts are one audit row so a retry is not shown as another stock movement.'] : null,
                    $audit->error_message ? ['Error', $audit->error_message] : null,
                ]),
                links: array_filter([
                    $this->productLink($audit->product_id),
                    $this->shopifyProductLink($audit->variant?->product?->shopify_id),
                ]),
                represented: $keys,
                actorUserId: $audit->actor_user_id,
                process: $audit->process_name,
            );
        })->all();
    }

    /**
     * @param array<int, array<string, mixed>> $local
     * @param array<int, array<string, mixed>> $audits
     * @return array<int, array<string, mixed>>
     */
    /**
     * @param array<int, array<string, mixed>> $remotes
     * @return array{movements:array<int, int>,receipts:array<int, string>,manual:array<int, int>}
     */
    private function citedReferences(array $remotes): array
    {
        $movements = [];
        $receipts = [];
        $manual = [];
        foreach ($remotes as $remote) {
            $reference = trim((string) ($remote['reference'] ?? ''));
            if (preg_match('#StackInventoryMovement/(\d+)$#', $reference, $match) === 1) {
                $movements[] = (int) $match[1];
            }
            if (str_contains($reference, 'procurement-receipt/')) {
                $receipts[] = $reference;
            }
            if (preg_match('#ManualStockTransaction/\d+/Impact/(\d+)$#', $reference, $match) === 1) {
                $manual[] = (int) $match[1];
            }
        }

        return [
            'movements' => array_values(array_unique($movements)),
            'receipts' => array_values(array_unique($receipts)),
            'manual' => array_values(array_unique($manual)),
        ];
    }

    /**
     * @param array<int, array<string, mixed>> $local
     * @return array<int, array<string, mixed>>
     */
    private function uniqueLocal(array $local): array
    {
        $unique = [];
        foreach ($local as $row) {
            $unique[(string) ($row['row']['key'] ?? spl_object_id((object) $row))] = $row;
        }

        return array_values($unique);
    }

    private function attachOperationAudits(array $local, array $audits): array
    {
        $consumed = [];
        foreach ($local as &$row) {
            foreach ($audits as $index => $audit) {
                if (isset($consumed[$index])) {
                    continue;
                }
                $shared = array_intersect((array) ($row['represented'] ?? []), (array) ($audit['represented'] ?? []));
                if ($shared === []) {
                    continue;
                }
                $consumed[$index] = true;
                $row['row']['detail'][] = [
                    'CMS operation record',
                    ($audit['row']['status_label'] ?? 'Recorded').'. Repeated attempts stay on one record so a retry is not listed as another stock movement.',
                ];
            }
        }
        unset($row);

        foreach ($audits as $index => $audit) {
            if (! isset($consumed[$index])) {
                $local[] = $audit;
            }
        }

        return $local;
    }

    /**
     * @param \Illuminate\Support\Collection<int, mixed> $records
     * @return \Illuminate\Support\Collection<int, mixed>
     */
    private function cap(\Illuminate\Support\Collection $records): \Illuminate\Support\Collection
    {
        if ($records->count() > self::SOURCE_CAP) {
            $this->hitCap = true;

            return $records->take(self::SOURCE_CAP);
        }

        return $records;
    }

    /**
     * @param array<string, mixed> $remote
     * @return array<string, mixed>
     */
    private function shopifyCandidate(array $remote): array
    {
        $reason = strtolower(trim((string) ($remote['inventory_change_reason'] ?? '')));
        $state = strtolower(trim((string) ($remote['inventory_state'] ?? '')));
        $delta = (int) ($remote['inventory_adjustment_change'] ?? 0);
        $documentType = strtolower(trim((string) ($remote['reference_document_type'] ?? '')));
        $activityKey = match (true) {
            $reason === 'purchase' && $documentType === 'order' => 'order_created',
            $reason === 'received' => 'received',
            $reason === 'correction' => 'correction',
            $reason === 'reservation_created', $reason === 'reservation_updated' => 'reserved',
            $reason === 'reservation_deleted' => 'reservation_deleted',
            default => 'shopify_other',
        };
        $activity = match (true) {
            $reason === 'purchase' && $documentType === 'order' => 'Order created',
            $reason === 'received' => 'Inventory received',
            $reason === 'correction' => 'Inventory correction',
            $reason === 'restock' => 'Items restocked',
            $reason === 'reservation_created' => 'Reservation created',
            $reason === 'reservation_deleted' => 'Reservation deleted',
            $reason === 'reservation_updated' => 'Reservation updated',
            $reason === 'damaged' => 'Damaged',
            $reason === 'shrinkage' => 'Theft or loss',
            $reason === 'promotion' => 'Promotion or donation',
            default => $reason !== '' ? ucfirst(str_replace('_', ' ', $reason)) : 'Inventory adjustment',
        };
        if ($state !== '') {
            $activity .= ' · '.str_replace('_', ' ', $state);
        }
        $app = trim((string) ($remote['inventory_app_name'] ?? ''));
        $staff = trim((string) ($remote['staff_member_name'] ?? ''));
        $cmsApp = strtolower($app) === 'la-cms-access';
        $source = $cmsApp ? 'cms' : (str_contains(strtolower($app), 'online store') ? 'online_store' : 'shopify');
        $occurredAt = $this->shopifyTimestamp($remote['second'] ?? null);
        $adjustmentId = trim((string) ($remote['inventory_adjustment_id'] ?? ''));
        $referenceUri = trim((string) ($remote['reference_document_uri'] ?? ''));
        $groupId = trim((string) ($remote['inventory_adjustment_group_id'] ?? ''));
        $second = trim((string) ($remote['second'] ?? ''));
        $process = match (true) {
            str_contains($referenceUri, 'StackInventoryMovement') => 'Stack inventory movement',
            str_contains($referenceUri, 'procurement-receipt') => 'Supplier receipt job',
            str_contains($referenceUri, 'ManualStockTransaction') => 'Manual stock job',
            str_contains($referenceUri, 'logistics://shopify-editor/inventory/') => 'Inventory correction',
            default => null,
        };
        if ($process === 'Stack inventory movement' && $reason === 'correction') {
            $activity = 'Stack inventory adjustment'.($state !== '' ? ' · '.str_replace('_', ' ', $state) : '');
            $activityKey = 'correction';
        }
        $groupKey = $referenceUri !== ''
            ? 'ref:'.strtolower($referenceUri).'|'.$second.'|'.$reason
            : ($groupId !== '' ? 'group:'.$groupId : ($adjustmentId !== '' ? 'adjustment:'.$adjustmentId : ''));

        return [
            'occurred_at' => $occurredAt,
            'reference' => (string) ($remote['reference_document_uri'] ?? ''),
            'inventory_item_id' => (string) ($remote['inventory_item_id'] ?? ''),
            'location_id' => (string) ($remote['inventory_location_id'] ?? ''),
            'delta' => $delta,
            'state' => $state,
            'reason' => $reason,
            'evidence' => array_values(array_filter([
                $adjustmentId !== '' ? 'Adjustment '.$adjustmentId : null,
                trim((string) ($remote['inventory_adjustment_group_id'] ?? '')) !== '' ? 'Group '.$remote['inventory_adjustment_group_id'] : null,
                $app !== '' ? 'Application '.$app : null,
                $staff !== '' ? 'Shopify staff '.$staff : 'Shopify did not name a staff member',
                trim((string) ($remote['reference_document_type'] ?? '')) !== '' ? 'Document type '.$remote['reference_document_type'] : null,
                trim((string) ($remote['reference_document_uri'] ?? '')) !== '' ? 'Reference '.$remote['reference_document_uri'] : null,
            ])),
            'row' => [
                'key' => 'shopify:'.($adjustmentId !== '' ? $adjustmentId : md5(json_encode($remote))),
                'occurred_at' => $occurredAt?->timezone(config('app.timezone'))->format('M j, Y H:i') ?? (string) ($remote['second'] ?? ''),
                'occurred_at_iso' => $occurredAt?->toIso8601String() ?? '',
                'activity_key' => $activityKey,
                'activity' => $activity,
                'change' => ($delta > 0 ? '+' : '').$delta.($state !== '' ? ' '.$state : ''),
                'available' => null,
                'on_hand' => null,
                'source' => $source,
                'source_label' => match ($source) {
                    'online_store' => 'Online Store',
                    'cms' => 'CMS',
                    default => 'Shopify',
                },
                'triggered_by' => $staff !== '' ? $staff : ($app !== '' ? $app : 'Not recorded by Shopify'),
                'confidence' => 'unmatched',
                'confidence_label' => 'Unmatched',
                'status' => 'reported',
                'status_label' => 'Reported by Shopify',
                'location' => trim((string) (($remote['inventory_location_name'] ?? '') ?: ($remote['inventory_location_id'] ?? ''))),
                'detail' => array_values(array_filter([
                    ['SKU', $remote['product_variant_sku'] ?? null],
                    ['Variant', $remote['product_variant_title'] ?? null],
                    ['State', $state !== '' ? $state : null],
                    ['Reason', $reason !== '' ? $reason : null],
                    ['Location', $remote['inventory_location_name'] ?? null],
                    ['Application', $app !== '' ? $app : null],
                    ['Workflow from the Shopify reference', $process],
                    str_contains($referenceUri, 'logistics://shopify-editor/inventory/')
                        ? ['Reference limitation', 'This correction reuses one reference per variant, so the reference cannot identify which local request it was.']
                        : null,
                    ['Staff recorded by Shopify', $staff !== '' ? $staff : null],
                    ['Resulting quantity', 'ShopifyQL inventory_adjustment_history does not include the available or on-hand balance after the change.'],
                    ['Record origin', 'Reported by Shopify'],
                ])),
                'links' => $this->shopifyReferenceLinks($remote),
                'sku' => trim((string) ($remote['product_variant_sku'] ?? '')) ?: null,
                'product' => trim((string) ($remote['product_variant_title'] ?? '')) ?: null,
                'app' => $app !== '' ? $app : null,
                'reason' => $reason !== '' ? $reason : null,
                'process' => $process,
                'actor_user_id' => null,
                'expected_state' => null,
                'expected_delta' => null,
                'states' => $state !== '' ? [['state' => $state, 'delta' => $delta, 'origin' => 'shopify']] : [],
                'group_key' => $groupKey !== '' ? $groupKey : null,
                'reported_by' => 'shopify',
                'identifiers' => array_values(array_filter([$adjustmentId !== '' ? $adjustmentId : null, $referenceUri !== '' ? $referenceUri : null])),
                'flags' => [],
                'flag_labels' => [],
                'unexplained' => false,
            ],
        ];
    }

    private function singleVariant(string $query): ?Variant
    {
        if ($query === '') {
            return null;
        }

        $exact = Variant::query()->where('sku', $query)->limit(2)->get();
        if ($exact->count() === 1) {
            return $exact->first();
        }
        if ($exact->count() > 1) {
            return null;
        }

        $byTitle = Variant::query()
            ->whereHas('product', fn (Builder $product): Builder => $product->where('title', $query))
            ->limit(2)
            ->get();

        return $byTitle->count() === 1 ? $byTitle->first() : null;
    }

    /**
     * A sale, return, or Online Store movement is Shopify's own order activity.
     * A correction made by a person in Shopify admin stays in the report.
     *
     * @param array<string, mixed> $remote
     */
    private function isShopifySale(array $remote): bool
    {
        $reason = strtolower(trim((string) ($remote['inventory_change_reason'] ?? '')));
        $app = strtolower(trim((string) ($remote['inventory_app_name'] ?? '')));
        $documentType = strtolower(trim((string) ($remote['reference_document_type'] ?? '')));

        return in_array($reason, ['purchase', 'restock'], true)
            || $documentType === 'order'
            || str_contains($app, 'online store');
    }

    /**
     * Quantity edits a CMS user saved. These are read from the change log and are not matched to Shopify.
     *
     * @return array<int, array<string, mixed>>
     */
    private function personChangeLogs(Carbon $from, Carbon $until, string $query, string $reference): array
    {
        if ($reference !== '') {
            return [];
        }

        $logFrom = $query !== '' ? $until->copy()->subDays(1095)->startOfDay() : $from;
        $variantIds = $query === ''
            ? []
            : Variant::query()->where('sku', 'like', '%'.$query.'%')->pluck('id')->all();
        $records = ChangeLog::query()
            ->with(['changedBy', 'product'])
            ->whereNotNull('changed_by')
            ->whereIn('field', array_keys(self::PERSON_INVENTORY_FIELDS))
            ->whereBetween('created_at', [$logFrom, $until])
            ->when($query !== '', function (Builder $builder) use ($query, $variantIds): void {
                $builder->where(function (Builder $inner) use ($query, $variantIds): void {
                    $inner->whereHas('product', fn (Builder $product): Builder => $product->where('title', 'like', '%'.$query.'%'))
                        ->orWhereHas('product.variants', fn (Builder $variant): Builder => $variant->where('sku', 'like', '%'.$query.'%'))
                        ->orWhere(function (Builder $variantLog) use ($variantIds): void {
                            $variantLog->where('model_type', Variant::class)->whereIn('model_id', $variantIds !== [] ? $variantIds : [0]);
                        });
                });
            })
            ->orderByDesc('id')
            ->limit(self::SOURCE_CAP + 1)
            ->get();
        $records = $this->cap($records);
        $variants = Variant::query()
            ->whereIn('id', $records->where('model_type', Variant::class)->pluck('model_id')->filter()->unique()->all())
            ->get()
            ->keyBy('id');

        return $records->map(function (ChangeLog $log) use ($variants): array {
            [$state, $activity, $stateLabel] = self::PERSON_INVENTORY_FIELDS[$log->field];
            $variant = $log->model_type === Variant::class ? $variants->get($log->model_id) : null;
            $old = $this->numericQuantity($log->old_value);
            $new = $this->numericQuantity($log->new_value);
            $delta = $old !== null && $new !== null ? $new - $old : 0;
            $change = trim((string) $log->old_value).' → '.trim((string) $log->new_value).' '.$stateLabel;
            if ($old !== null && $new !== null) {
                $change .= ' ('.($delta > 0 ? '+' : '').$delta.')';
            }

            return $this->candidate(
                key: 'change-log:'.$log->id,
                occurredAt: Carbon::parse($log->created_at ?? now()),
                activityKey: 'cms_edit',
                activity: $activity,
                change: $change,
                available: null,
                onHand: $state === 'on_hand' && $new !== null ? (string) $new : null,
                source: 'cms',
                triggeredBy: $this->actor($log->changedBy, 'CMS user', false),
                status: 'recorded',
                location: null,
                references: [],
                inventoryItemId: $variant?->shopify_inventory_item_id,
                locationId: null,
                delta: $delta,
                state: $state,
                detail: [
                    ['SKU', $variant?->sku],
                    ['Product', $log->product?->title ?? $variant?->product?->title],
                    ['Field', $log->field],
                    ['Old value', $log->old_value],
                    ['New value', $log->new_value],
                    ['Changed by', $log->changedBy?->name],
                    ['Reason', 'Edited in the CMS by this user'],
                    ['Record origin', 'CMS change log'],
                ],
                links: array_filter([
                    $this->productLink($log->product_id ?? $variant?->product_id),
                ]),
                represented: [],
                actorUserId: $log->changed_by ? (int) $log->changed_by : null,
                process: 'CMS change log',
            );
        })->all();
    }

    private function numericQuantity(mixed $value): ?int
    {
        $value = trim((string) $value);
        if ($value === '' || ! is_numeric($value)) {
            return null;
        }

        return (int) $value;
    }

    /**
     * @param array<int, string|null> $references
     * @param array<int, array{0:string,1?:string|null}|null> $detail
     * @param array<int, array{0:string,1:string}|null> $links
     * @param array<int, string|null> $represented
     * @return array<string, mixed>
     */
    private function candidate(
        string $key,
        Carbon $occurredAt,
        string $activityKey,
        string $activity,
        string $change,
        ?string $available,
        ?string $onHand,
        string $source,
        string $triggeredBy,
        string $status,
        ?string $location,
        array $references,
        ?string $inventoryItemId,
        ?string $locationId,
        int $delta,
        string $state,
        array $detail,
        array $links,
        array $represented,
        ?int $actorUserId = null,
        ?string $process = null,
    ): array {
        $detail = array_values(array_filter(array_map(function ($line): ?array {
            if (! is_array($line) || ! isset($line[0]) || $line[1] === null || $line[1] === '') {
                return null;
            }

            return [$line[0], (string) $line[1]];
        }, $detail)));
        $detail[] = ['Shopify history match', 'Not linked to a Shopify adjustment-history row.'];
        $sku = $this->detailValue($detail, 'SKU') ?? $this->detailValue($detail, 'Component SKU');
        $product = $this->detailValue($detail, 'Product') ?? $this->detailValue($detail, 'Component');
        $reasonText = $this->detailValue($detail, 'Reason');
        if ($actorUserId) {
            $userUrl = $this->safeUrl(fn (): string => UserResource::getUrl('edit', ['record' => $actorUserId]));
            if ($userUrl) {
                $links[] = ['User', $userUrl];
            }
        }

        return [
            'occurred_at' => $occurredAt,
            'references' => array_values(array_filter($references)),
            'inventory_item_id' => (string) $inventoryItemId,
            'location_id' => (string) $locationId,
            'delta' => $delta,
            'state' => $state,
            'represented' => array_values(array_filter($represented)),
            'row' => [
                'key' => $key,
                'occurred_at' => $occurredAt->timezone(config('app.timezone'))->format('M j, Y H:i'),
                'occurred_at_iso' => $occurredAt->toIso8601String(),
                'activity_key' => $activityKey,
                'activity' => $activity,
                'change' => $change,
                'available' => $available,
                'on_hand' => $onHand,
                'source' => $source,
                'source_label' => match ($source) {
                    'automation' => 'Automation',
                    'online_store' => 'Online Store',
                    'shopify' => 'Shopify',
                    default => 'CMS',
                },
                'triggered_by' => $triggeredBy,
                'confidence' => 'local',
                'confidence_label' => 'Local record',
                'status' => $status,
                'status_label' => $this->statusLabel($status),
                'location' => (string) $location,
                'detail' => $detail,
                'links' => array_values(array_filter($links)),
                'sku' => $sku,
                'product' => $product,
                'app' => in_array($source, ['cms', 'automation'], true) ? 'la-cms-access' : null,
                'reason' => $reasonText,
                'process' => $process,
                'actor_user_id' => $actorUserId,
                'expected_state' => $state,
                'expected_delta' => $delta,
                'states' => $state !== '' ? [['state' => $state, 'delta' => $delta, 'origin' => 'local']] : [],
                'group_key' => null,
                'reported_by' => 'local',
                'identifiers' => array_values(array_filter($references)),
                'flags' => [],
                'flag_labels' => [],
                'unexplained' => false,
            ],
        ];
    }

    private function actor(?User $user, string $process, bool $background = true): string
    {
        if ($user instanceof User && $background) {
            return $user->name.' (requested; '.$process.' applied it)';
        }
        if ($user instanceof User) {
            return $user->name;
        }

        return $process;
    }

    private function statusLabel(string $status): string
    {
        return match (strtolower($status)) {
            'succeeded', 'completed', 'applied' => 'Applied',
            'failed' => 'Failed',
            'manual_review' => 'Needs review',
            'processing' => 'In progress',
            'historical' => 'Historical record',
            'reported' => 'Reported by Shopify',
            default => $status !== '' ? ucfirst(str_replace('_', ' ', strtolower($status))) : 'Not recorded',
        };
    }

    /**
     * @return array{0:string,1:string}|null
     */
    private function productLink(?int $productId): ?array
    {
        if (! $productId) {
            return null;
        }

        $url = $this->safeUrl(fn (): string => ProductResource::getUrl('edit', ['record' => $productId]));

        return $url ? ['Product', $url] : null;
    }

    /**
     * @return array{0:string,1:string}|null
     */
    private function shopifyProductLink(?string $shopifyId): ?array
    {
        $shop = trim((string) config('services.shopify.shop'));
        $id = trim((string) $shopifyId);
        if ($shop === '' || $id === '') {
            return null;
        }
        $position = strrpos($id, '/');
        $numeric = $position === false ? $id : substr($id, $position + 1);
        if ($numeric === '') {
            return null;
        }

        return ['Shopify product', 'https://'.$shop.'/admin/products/'.$numeric];
    }

    /**
     * @param array<string, mixed> $remote
     * @return array<int, array{0:string,1:string}>
     */
    private function shopifyReferenceLinks(array $remote): array
    {
        $type = strtolower(trim((string) ($remote['reference_document_type'] ?? '')));
        $uri = trim((string) ($remote['reference_document_uri'] ?? ''));
        if ($type !== 'order' || $uri === '') {
            return [];
        }
        $shop = trim((string) config('services.shopify.shop'));
        $position = strrpos($uri, '/');
        $numeric = $position === false ? $uri : substr($uri, $position + 1);
        if ($shop === '' || $numeric === '') {
            return [];
        }

        return [['Shopify order', 'https://'.$shop.'/admin/orders/'.$numeric]];
    }

    private function safeUrl(callable $callback): ?string
    {
        try {
            $url = $callback();

            return is_string($url) && $url !== '' ? $url : null;
        } catch (\Throwable) {
            return null;
        }
    }

    /**
     * @param array<int, array<string, mixed>> $rows
     * @return array<int, array<string, mixed>>
     */
    private function enrichProducts(array $rows): array
    {
        $skus = [];
        foreach ($rows as $row) {
            $sku = trim((string) ($row['row']['sku'] ?? ''));
            if ($sku !== '') {
                $skus[$sku] = true;
            }
        }
        if ($skus === []) {
            return $rows;
        }
        $variants = Variant::query()->with('product')->whereIn('sku', array_keys($skus))->get()->keyBy(fn (Variant $variant): string => (string) $variant->sku);
        foreach ($rows as &$row) {
            $variant = $variants->get((string) ($row['row']['sku'] ?? ''));
            $title = trim((string) ($variant?->product?->title ?? ''));
            $current = trim((string) ($row['row']['product'] ?? ''));
            if ($title !== '' && ($current === '' || strcasecmp($current, 'Default Title') === 0)) {
                $row['row']['product'] = $title;
            }
            if ($variant?->product_id && ! $this->hasLink($row['row']['links'] ?? [], 'Product')) {
                $link = $this->productLink($variant->product_id);
                if ($link) {
                    $row['row']['links'][] = $link;
                }
            }
            if ($variant?->product?->shopify_id && ! $this->hasLink($row['row']['links'] ?? [], 'Shopify product')) {
                $link = $this->shopifyProductLink($variant->product->shopify_id);
                if ($link) {
                    $row['row']['links'][] = $link;
                }
            }
        }
        unset($row);

        return $rows;
    }

    /**
     * @param array<int, mixed> $links
     */
    private function hasLink(array $links, string $label): bool
    {
        foreach ($links as $link) {
            if (is_array($link) && ($link[0] ?? '') === $label) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param array<string, mixed> $remote
     */
    private function remoteMatchesQuery(array $remote, string $needle): bool
    {
        $haystack = strtolower(trim((string) ($remote['product_variant_sku'] ?? '')).' '.trim((string) ($remote['product_variant_title'] ?? '')));

        return $haystack !== '' && str_contains($haystack, $needle);
    }

    /**
     * @param array<string, mixed> $row
     */
    private function hasState(array $row, string $state): bool
    {
        $state = strtolower($state);
        foreach ((array) ($row['states'] ?? []) as $line) {
            if (is_array($line) && strtolower((string) ($line['state'] ?? '')) === $state) {
                return true;
            }
        }

        return str_contains(strtolower((string) ($row['change'] ?? '')), str_replace('_', ' ', $state));
    }

    /**
     * @param array<string, mixed> $row
     */
    private function hasDirection(array $row, bool $positive): bool
    {
        foreach ((array) ($row['states'] ?? []) as $line) {
            if (! is_array($line)) {
                continue;
            }
            $delta = (int) ($line['delta'] ?? 0);
            if ($positive ? $delta > 0 : $delta < 0) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param array<string, mixed> $row
     */
    private function isCmsRow(array $row): bool
    {
        if (strtolower((string) ($row['app'] ?? '')) === 'la-cms-access') {
            return true;
        }

        return in_array((string) ($row['source'] ?? ''), ['cms', 'automation'], true);
    }

    /**
     * @param array<int, array<string, mixed>> $rows
     * @return array<int, array<string, mixed>>
     */
    private function attachReviews(array $rows): array
    {
        $keys = array_values(array_filter(array_map(fn (array $row): string => (string) ($row['key'] ?? ''), $rows)));
        if ($keys === []) {
            return $rows;
        }
        $reviews = InventoryAuditReview::query()->with('reviewer')->whereIn('operation_key', $keys)->get()->keyBy('operation_key');
        foreach ($rows as &$row) {
            $review = $reviews->get((string) ($row['key'] ?? ''));
            $row['review_status'] = $review?->status;
            $row['review_note'] = $review?->note;
            $row['reviewed_at'] = $review?->reviewed_at?->timezone(config('app.timezone'))->format('M j, Y H:i');
            $row['reviewed_by'] = $review?->reviewer?->name;
        }
        unset($row);

        return $rows;
    }

    /**
     * @param array<int, array<string, mixed>> $rows
     * @return array<string, int>
     */
    public function summary(array $rows): array
    {
        $positive = 0;
        $negative = 0;
        $products = [];
        $cms = 0;
        $shopify = 0;
        $unexplained = 0;
        foreach ($rows as $row) {
            if ($this->isCmsRow($row)) {
                $cms++;
            } elseif (in_array((string) ($row['source'] ?? ''), ['shopify', 'online_store'], true)) {
                $shopify++;
            }
            if (($row['flags'] ?? []) !== []) {
                $unexplained++;
            }
            $sku = trim((string) ($row['sku'] ?? ''));
            if ($sku !== '') {
                $products[$sku] = true;
            }
            $states = array_values(array_filter((array) ($row['states'] ?? []), 'is_array'));
            $shopifyLines = array_values(array_filter($states, fn (array $state): bool => ($state['origin'] ?? '') === 'shopify'));
            foreach ($shopifyLines !== [] ? $shopifyLines : $states as $state) {
                $delta = (int) ($state['delta'] ?? 0);
                if ($delta > 0) {
                    $positive++;
                } elseif ($delta < 0) {
                    $negative++;
                }
            }
        }

        return [
            'operations' => count($rows),
            'cms_operations' => $cms,
            'shopify_operations' => $shopify,
            'unexplained' => $unexplained,
            'products' => count($products),
            'positive_lines' => $positive,
            'negative_lines' => $negative,
        ];
    }

    /**
     * @param array<int, array{0:string,1:string}> $detail
     */
    private function detailValue(array $detail, string $label): ?string
    {
        foreach ($detail as $line) {
            if (($line[0] ?? '') === $label && trim((string) ($line[1] ?? '')) !== '') {
                return (string) $line[1];
            }
        }

        return null;
    }

    private function shopifyTimestamp(mixed $value): ?Carbon
    {
        $value = trim((string) $value);
        if ($value === '' || strlen($value) <= 10) {
            return null;
        }

        try {
            return Carbon::parse($value);
        } catch (\Throwable) {
            return null;
        }
    }
}
