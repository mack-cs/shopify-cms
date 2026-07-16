<?php

namespace App\Services\Shopify;

use App\Models\ShopifyOrder;
use App\Models\ShopifyOrderItem;
use App\Models\ShopifySyncIssue;
use App\Models\ShopifySyncRun;
use App\Models\Variant;

final class ShopifyOrderJsonlProcessor
{
    public function __construct(
        private readonly ShopifyOrderUpsertService $upserts,
    ) {
    }

    /**
     * @return array{source_lines:int,orders:int,order_items:int,refunds:int,discounts:int,unclassified:int,invalid_json:int,affected_skus:array<int, string>,affected_dates:array<int, string>}
     */
    public function process(string $gzPath, ShopifySyncRun $run): array
    {
        $handle = gzopen($gzPath, 'rb');
        if ($handle === false) {
            throw new \RuntimeException("Unable to open Shopify orders archive {$gzPath}.");
        }

        $counts = [
            'source_lines' => 0,
            'orders' => 0,
            'order_items' => 0,
            'refunds' => 0,
            'discounts' => 0,
            'unclassified' => 0,
            'invalid_json' => 0,
            'affected_skus' => [],
            'affected_dates' => [],
        ];

        try {
            while (!gzeof($handle)) {
                $line = gzgets($handle);
                if ($line === false) {
                    break;
                }

                $line = trim($line);
                if ($line === '') {
                    continue;
                }

                $counts['source_lines']++;
                $record = json_decode($line, true);

                if (!is_array($record)) {
                    $counts['invalid_json']++;
                    $this->issue($run, ShopifySyncIssue::TYPE_INVALID_JSON, 'Invalid JSONL row encountered.', ['line' => $counts['source_lines']]);
                    continue;
                }

                $this->processRecord($record, $run, $counts);
            }
        } finally {
            gzclose($handle);
        }

        $this->upserts->relinkItemsAndChildren();

        $counts['affected_skus'] = array_values(array_unique(array_filter($counts['affected_skus'])));
        $counts['affected_dates'] = array_values(array_unique(array_filter($counts['affected_dates'])));

        $run->forceFill([
            'records_processed' => $counts['source_lines'],
            'orders_processed' => $counts['orders'],
            'order_items_processed' => $counts['order_items'],
            'refunds_processed' => $counts['refunds'],
            'discounts_processed' => $counts['discounts'],
            'metadata' => array_merge($run->metadata ?? [], [
                'source_lines' => $counts['source_lines'],
                'unclassified' => $counts['unclassified'],
                'invalid_json' => $counts['invalid_json'],
                'affected_skus' => $counts['affected_skus'],
                'affected_dates' => $counts['affected_dates'],
            ]),
        ])->save();

        if ($counts['invalid_json'] > 0) {
            throw new \RuntimeException("Shopify orders JSONL contained {$counts['invalid_json']} invalid JSON row(s).");
        }

        return $counts;
    }

    /**
     * @param array<string, mixed> $record
     * @param array<string, mixed> $counts
     */
    private function processRecord(array $record, ShopifySyncRun $run, array &$counts): void
    {
        $id = trim((string) ($record['id'] ?? ''));

        if (str_starts_with($id, 'gid://shopify/Order/')) {
            $order = $this->upserts->upsertOrder($record, $run);
            $counts['orders']++;
            $this->processNestedOrderData($record, $run, $order, $counts);

            return;
        }

        if (str_starts_with($id, 'gid://shopify/LineItem/')) {
            $item = $this->upserts->upsertOrderItem($record, $run);
            $counts['order_items']++;
            $this->recordOrderItemMappingIssue($run, $item);
            $this->trackAffectedItem($item, $counts);

            return;
        }

        if (array_key_exists('allocationMethod', $record) || array_key_exists('targetSelection', $record)) {
            $discount = $this->upserts->upsertDiscount($record, $run);
            if ($discount !== null) {
                $counts['discounts']++;
            }

            return;
        }

        $counts['unclassified']++;
        $this->issue($run, ShopifySyncIssue::TYPE_UNCLASSIFIED_RECORD, 'Unclassified Shopify orders JSONL record.', [
            'id' => $id,
            'keys' => array_keys($record),
        ]);
    }

    /**
     * @param array<string, mixed> $record
     * @param array<string, mixed> $counts
     */
    private function processNestedOrderData(array $record, ShopifySyncRun $run, ShopifyOrder $order, array &$counts): void
    {
        foreach ($record['refunds'] ?? [] as $refund) {
            if (is_array($refund) && $this->upserts->upsertRefund($refund, $run, $order) !== null) {
                $counts['refunds']++;
            }
        }

        foreach (data_get($record, 'lineItems.edges', []) as $edge) {
            $node = data_get($edge, 'node');
            if (!is_array($node)) {
                continue;
            }

            $node['__parentId'] = $order->shopify_order_id;
            $item = $this->upserts->upsertOrderItem($node, $run, $order);
            $counts['order_items']++;
            $this->recordOrderItemMappingIssue($run, $item);
            $this->trackAffectedItem($item, $counts);
        }

        foreach (data_get($record, 'discountApplications.edges', []) as $edge) {
            $node = data_get($edge, 'node');
            if (!is_array($node)) {
                continue;
            }

            $node['__parentId'] = $order->shopify_order_id;
            if ($this->upserts->upsertDiscount($node, $run, $order) !== null) {
                $counts['discounts']++;
            }
        }
    }

    /**
     * @param array<string, mixed> $counts
     */
    private function trackAffectedItem(mixed $item, array &$counts): void
    {
        if (!$item instanceof ShopifyOrderItem) {
            return;
        }

        if ($item->sku !== null && $item->sku !== '') {
            $counts['affected_skus'][] = $item->sku;
        }

        $date = $item->order?->processed_at_shopify ?? $item->order?->created_at_shopify ?? $item->order_created_at_shopify;
        if ($date !== null) {
            $counts['affected_dates'][] = $date->copy()
                ->setTimezone((string) config('shopify_sync.timezone', 'Africa/Johannesburg'))
                ->toDateString();
        }
    }

    private function recordOrderItemMappingIssue(ShopifySyncRun $run, ?ShopifyOrderItem $item): void
    {
        if (!$item instanceof ShopifyOrderItem) {
            return;
        }

        $sku = strtoupper(trim((string) ($item->sku ?? '')));
        if ($sku === '') {
            ShopifySyncIssue::query()->updateOrCreate([
                'sync_run_id' => $run->id,
                'dataset' => ShopifySyncRun::DATASET_ORDERS,
                'issue_type' => ShopifySyncIssue::TYPE_MISSING_SKU,
                'shopify_id' => $item->shopify_line_item_id,
            ], [
                'parent_shopify_id' => $item->shopify_order_id,
                'message' => 'Shopify order line item has no SKU.',
            ]);

            return;
        }

        $matches = Variant::query()
            ->active()
            ->whereRaw('UPPER(TRIM(COALESCE(sku, ""))) = ?', [$sku])
            ->limit(3)
            ->get();

        if ($matches->isEmpty()) {
            ShopifySyncIssue::query()->updateOrCreate([
                'sync_run_id' => $run->id,
                'dataset' => ShopifySyncRun::DATASET_ORDERS,
                'issue_type' => ShopifySyncIssue::TYPE_UNMATCHED_SKU,
                'shopify_id' => $item->shopify_line_item_id,
            ], [
                'parent_shopify_id' => $item->shopify_order_id,
                'sku' => $sku,
                'message' => 'Shopify order line item SKU did not match any local variant.',
            ]);

            return;
        }

        if ($matches->count() > 1) {
            ShopifySyncIssue::query()->updateOrCreate([
                'sync_run_id' => $run->id,
                'dataset' => ShopifySyncRun::DATASET_ORDERS,
                'issue_type' => ShopifySyncIssue::TYPE_DUPLICATE_SKU,
                'shopify_id' => $item->shopify_line_item_id,
            ], [
                'parent_shopify_id' => $item->shopify_order_id,
                'sku' => $sku,
                'message' => 'Shopify order line item SKU matched multiple local variants.',
                'payload' => [
                    'variant_ids' => $matches->pluck('id')->values()->all(),
                ],
            ]);
        }
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function issue(ShopifySyncRun $run, string $type, string $message, array $payload): void
    {
        ShopifySyncIssue::query()->updateOrCreate([
            'sync_run_id' => $run->id,
            'dataset' => ShopifySyncRun::DATASET_ORDERS,
            'issue_type' => $type,
            'shopify_id' => $payload['id'] ?? null,
        ], [
            'message' => $message,
            'payload' => $payload,
        ]);
    }
}
