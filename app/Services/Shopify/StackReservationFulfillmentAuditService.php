<?php

namespace App\Services\Shopify;

use App\Models\ShopifyStackInventoryReservation;
use League\Csv\Writer;

final class StackReservationFulfillmentAuditService
{
    public const ISSUE_FULFILLED = 'leftover_on_fulfilled';

    public const ISSUE_CANCELLED = 'leftover_on_cancelled';

    public const ISSUE_OPEN = 'leftover_on_open';

    public const ISSUE_UNKNOWN = 'leftover_unknown';

    public function __construct(private readonly StackOrderFulfillmentInspector $fulfillment) {}

    /**
     * @return array{
     *     rows: array<int, array<string, mixed>>,
     *     leftover_rows: int,
     *     leftover_orders: int,
     *     fulfilled_rows: int,
     *     fulfilled_orders: int,
     *     cancelled_rows: int,
     *     open_rows: int,
     *     unknown_rows: int,
     *     fulfilled_reservation_ids: array<int, int>
     * }
     */
    public function report(): array
    {
        $reservations = ShopifyStackInventoryReservation::query()
            ->withLeftoverReserved()
            ->orderBy('shopify_order_name')
            ->orderBy('id')
            ->get();

        $snapshots = $this->fulfillment->snapshotsFor(
            $reservations->pluck('shopify_order_id')->filter()->unique()->values()->all()
        );

        $rows = [];
        $fulfilledIds = [];
        $orderIssues = [];
        foreach ($reservations as $reservation) {
            $snapshot = $snapshots[$reservation->shopify_order_id] ?? $this->fulfillment->snapshotFor($reservation->shopify_order_id);
            $issue = $this->issue($snapshot);
            $remaining = $reservation->remainingReserved();
            $rows[] = [
                'issue' => $issue,
                'reservation_id' => (int) $reservation->id,
                'shopify_order_name' => $reservation->shopify_order_name ?: ($snapshot['name'] ?? ''),
                'shopify_order_id' => $reservation->shopify_order_id,
                'shopify_fulfillment_status' => $snapshot['display_fulfillment_status'] ?? '',
                'shopify_cancelled_at' => $snapshot['cancelled_at'] ?? '',
                'ordered_at' => optional($reservation->shopify_order_created_at)?->toDateTimeString(),
                'stack_sku' => $reservation->stack_sku,
                'stack_title' => $reservation->stack_title,
                'component_sku' => $reservation->component_sku,
                'component_title' => $reservation->component_title,
                'stack_quantity_ordered' => (int) $reservation->stack_quantity_ordered,
                'required' => (int) $reservation->total_component_quantity_required,
                'reserved' => (int) $reservation->reserved_quantity,
                'consumed' => (int) $reservation->consumed_quantity,
                'released' => (int) $reservation->released_quantity,
                'remaining_reserved' => $remaining,
                'cms_status' => $reservation->status,
                'reserved_at' => optional($reservation->reserved_at)?->toDateTimeString(),
            ];
            $orderIssues[$reservation->shopify_order_id][$issue] = true;
            if ($issue === self::ISSUE_FULFILLED) {
                $fulfilledIds[] = (int) $reservation->id;
            }
        }

        return [
            'rows' => $rows,
            'leftover_rows' => count($rows),
            'leftover_orders' => count($orderIssues),
            'fulfilled_rows' => count($fulfilledIds),
            'fulfilled_orders' => collect($orderIssues)->filter(fn (array $issues): bool => isset($issues[self::ISSUE_FULFILLED]))->count(),
            'cancelled_rows' => collect($rows)->where('issue', self::ISSUE_CANCELLED)->count(),
            'open_rows' => collect($rows)->where('issue', self::ISSUE_OPEN)->count(),
            'unknown_rows' => collect($rows)->where('issue', self::ISSUE_UNKNOWN)->count(),
            'fulfilled_reservation_ids' => $fulfilledIds,
        ];
    }

    /** @param array{rows: array<int, array<string, mixed>>}|null $report */
    public function csv(?array $report = null): string
    {
        $report ??= $this->report();
        $writer = Writer::createFromString();
        $writer->insertOne([
            'Issue', 'Reservation ID', 'Order', 'Shopify Order ID', 'Shopify fulfillment', 'Shopify cancelled at',
            'Ordered at', 'Stack SKU', 'Stack', 'Component SKU', 'Component', 'Stack qty', 'Required',
            'Reserved', 'Consumed', 'Released', 'Remaining reserved', 'CMS status', 'Reserved at',
        ]);
        foreach ($report['rows'] as $row) {
            $writer->insertOne([
                $row['issue'], $row['reservation_id'], $row['shopify_order_name'], $row['shopify_order_id'],
                $row['shopify_fulfillment_status'], $row['shopify_cancelled_at'], $row['ordered_at'],
                $row['stack_sku'], $row['stack_title'], $row['component_sku'], $row['component_title'],
                $row['stack_quantity_ordered'], $row['required'], $row['reserved'], $row['consumed'],
                $row['released'], $row['remaining_reserved'], $row['cms_status'], $row['reserved_at'],
            ]);
        }

        return $writer->toString();
    }

    /**
     * @param  array{fulfilled:bool,known:bool,cancelled_at:?string}  $snapshot
     */
    private function issue(array $snapshot): string
    {
        if ($snapshot['fulfilled']) {
            return self::ISSUE_FULFILLED;
        }
        if (filled($snapshot['cancelled_at'] ?? null)) {
            return self::ISSUE_CANCELLED;
        }
        if ($snapshot['known']) {
            return self::ISSUE_OPEN;
        }

        return self::ISSUE_UNKNOWN;
    }
}
